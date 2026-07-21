<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Core\X402;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyEntity;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Dto\X402SessionContext;
use Swag\X402Payments\Core\X402\Exception\X402Exception;
use Swag\X402Payments\Core\X402\X402AmountConverter;
use Swag\X402Payments\Core\X402\X402QuoteHasher;
use Swag\X402Payments\Core\X402\X402RequirementBuilder;
use Swag\X402Payments\Tests\Unit\Support\X402Fixtures;

/**
 * Requirements generation (spec 12.1) and the metadata privacy regression
 * from spec 24.4: an order full of customer PII and product names must
 * produce requirements containing only opaque ids and the order number.
 */
// One test method per rejection case; splitting would hide the suite's shape.
// @mago-expect lint:too-many-methods
#[CoversClass(X402RequirementBuilder::class)]
final class X402RequirementBuilderTest extends TestCase
{
    private X402RequirementBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        $this->builder = new X402RequirementBuilder(
            new X402AmountConverter(),
            new X402QuoteHasher(),
            X402Fixtures::clock(),
        );
    }

    public function testAmountComesFromThePersistedOrderTransactionOnly(): void
    {
        $quote = $this->builder->build($this->sessionContext(transactionAmount: 42.99), Uuid::randomHex());

        self::assertSame('42990000', $quote->requirements->maxAmountRequired);
        self::assertSame(42.99, $quote->shopwareAmount);
        self::assertSame('USD', $quote->shopwareCurrency);
    }

    public function testRequirementsAreBoundToConfigAndResource(): void
    {
        $sessionId = Uuid::randomHex();
        $quote = $this->builder->build($this->sessionContext(), $sessionId);

        self::assertSame('exact', $quote->requirements->scheme);
        self::assertSame(X402Fixtures::NETWORK, $quote->requirements->network);
        self::assertSame(X402Fixtures::MERCHANT_WALLET, $quote->requirements->payTo);
        self::assertSame(X402Fixtures::ASSET_ADDRESS, $quote->requirements->asset);
        self::assertSame(X402Fixtures::RESOURCE_URL, $quote->requirements->resource);
        self::assertSame($sessionId, $quote->requirements->extra['paymentSessionId']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $quote->quoteHash);
    }

    public function testQuoteExpiryIsNowPlusConfiguredTimeout(): void
    {
        $quote = $this->builder->build($this->sessionContext(), Uuid::randomHex());

        self::assertSame(
            X402Fixtures::now()->modify('+300 seconds')->getTimestamp(),
            $quote->expiresAt->getTimestamp(),
        );
    }

    public function testCurrencyMismatchIsRejected(): void
    {
        try {
            $this->builder->build($this->sessionContext(currencyIso: 'EUR'), Uuid::randomHex());
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::CURRENCY_NOT_SUPPORTED, $exception->getErrorCode());
        }
    }

    public function testAmountBelowConfiguredMinimumIsRejected(): void
    {
        try {
            $this->builder->build($this->sessionContext(transactionAmount: 0.01), Uuid::randomHex());
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::AMOUNT_OUT_OF_BOUNDS, $exception->getErrorCode());
        }
    }

    public function testAmountAboveConfiguredMaximumIsRejected(): void
    {
        try {
            $this->builder->build($this->sessionContext(transactionAmount: 99999.0), Uuid::randomHex());
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::AMOUNT_OUT_OF_BOUNDS, $exception->getErrorCode());
        }
    }

    public function testRequirementsContainNoCustomerPiiOrLineItemNames(): void
    {
        $quote = $this->builder->build($this->sessionContext(), Uuid::randomHex());

        $serialized = json_encode($quote->requirements->toArray(), \JSON_THROW_ON_ERROR);

        foreach (['jane.doe@example.com', 'Jane', 'Doe', 'Monstera Deliciosa', 'Sesame Street'] as $pii) {
            self::assertStringNotContainsString($pii, $serialized, \sprintf('PII "%s" leaked into requirements', $pii));
        }

        self::assertStringContainsString('Shopware order 10042', $serialized);
    }

    public function testExtraNameFallsBackToAssetSymbolWhenEip712NameUnset(): void
    {
        $quote = $this->builder->build(
            $this->sessionContext(config: X402Fixtures::config(assetEip712Name: '')),
            Uuid::randomHex(),
        );

        self::assertSame('USDC', $quote->requirements->extra['name']);
        self::assertSame('2', $quote->requirements->extra['version']);
    }

    public function testExtraNameUsesConfiguredEip712NameWhenSet(): void
    {
        $quote = $this->builder->build(
            $this->sessionContext(config: X402Fixtures::config(assetEip712Name: 'USD Coin')),
            Uuid::randomHex(),
        );

        self::assertSame('USD Coin', $quote->requirements->extra['name']);
    }

    private function sessionContext(
        float $transactionAmount = 42.99,
        string $currencyIso = 'USD',
        ?\Swag\X402Payments\Core\X402\Config\X402Config $config = null,
    ): X402SessionContext {
        return new X402SessionContext(
            order: $this->order($currencyIso),
            transaction: $this->transaction($transactionAmount),
            config: $config ?? X402Fixtures::config(),
            salesChannelId: Uuid::randomHex(),
            resourceUrl: X402Fixtures::RESOURCE_URL,
            ownershipProof: X402PaymentSessionStates::OWNERSHIP_PROOF_CONTEXT_TOKEN,
            contextTokenHash: hash('sha256', 'context-token'),
            deepLinkCodeHash: null,
            idempotencyKeyHash: hash('sha256', 'idempotency-key'),
        );
    }

    private function order(string $currencyIso): OrderEntity
    {
        $currency = new CurrencyEntity();
        $currency->setId(Uuid::randomHex());
        $currency->setIsoCode($currencyIso);

        $customer = new OrderCustomerEntity();
        $customer->setId(Uuid::randomHex());
        $customer->setEmail('jane.doe@example.com');
        $customer->setFirstName('Jane');
        $customer->setLastName('Doe');

        $lineItem = new OrderLineItemEntity();
        $lineItem->setId(Uuid::randomHex());
        $lineItem->setUniqueIdentifier(Uuid::randomHex());
        $lineItem->setLabel('Monstera Deliciosa');

        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setOrderNumber('10042');
        $order->setCurrency($currency);
        $order->setOrderCustomer($customer);
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        return $order;
    }

    private function transaction(float $amount): OrderTransactionEntity
    {
        $transaction = new OrderTransactionEntity();
        $transaction->setId(Uuid::randomHex());
        $transaction->setAmount(
            new CalculatedPrice($amount, $amount, new CalculatedTaxCollection(), new TaxRuleCollection()),
        );

        return $transaction;
    }
}

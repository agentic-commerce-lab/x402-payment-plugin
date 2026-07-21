<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402;

use Psr\Clock\ClockInterface;
use Swag\X402Payments\Core\X402\Dto\PaymentRequirements;
use Swag\X402Payments\Core\X402\Dto\X402Quote;
use Swag\X402Payments\Core\X402\Dto\X402SessionContext;
use Swag\X402Payments\Core\X402\Exception\X402Exception;

/**
 * Builds x402 `exact` payment requirements from persisted Shopware order
 * transaction data only (spec section 12.1). Metadata stays PII-free: order
 * number and opaque ids, never customer or line item data (spec section 11).
 */
class X402RequirementBuilder
{
    public function __construct(
        private readonly X402AmountConverter $amountConverter,
        private readonly X402QuoteHasher $quoteHasher,
        private readonly ClockInterface $clock,
    ) {}

    public function build(X402SessionContext $sessionContext, string $paymentSessionId): X402Quote
    {
        $config = $sessionContext->config;
        $currency = $sessionContext->order->getCurrency()?->getIsoCode() ?? '';
        $amount = $sessionContext->transaction->getAmount()->getTotalPrice();

        if (!hash_equals(strtoupper($config->supportedCurrency), strtoupper($currency))) {
            throw X402Exception::currencyNotSupported($currency);
        }

        if ($amount < $config->minOrderAmount || $amount > $config->maxOrderAmount) {
            throw X402Exception::amountOutOfBounds();
        }

        $expiresAt = $this->clock->now()->add(new \DateInterval(\sprintf('PT%dS', $config->maxTimeoutSeconds)));

        $requirements = new PaymentRequirements(
            scheme: 'exact',
            network: $config->network,
            maxAmountRequired: $this->amountConverter->toAtomic($amount, $config->assetDecimals),
            asset: $config->assetAddress,
            payTo: $config->merchantWalletAddress,
            resource: $sessionContext->resourceUrl,
            description: \sprintf('Shopware order %s', $sessionContext->order->getOrderNumber() ?? ''),
            maxTimeoutSeconds: $config->maxTimeoutSeconds,
            extra: [
                'name' => $config->assetEip712Name !== '' ? $config->assetEip712Name : $config->assetSymbol,
                'version' => '2',
                'shopwareOrderTransactionId' => $sessionContext->transaction->getId(),
                'paymentSessionId' => $paymentSessionId,
            ],
        );

        return new X402Quote(
            requirements: $requirements,
            quoteHash: $this->quoteHasher->hash(
                $sessionContext->salesChannelId,
                $sessionContext->transaction->getId(),
                $requirements,
                $expiresAt->format(\DateTimeInterface::ATOM),
            ),
            atomicAmount: $requirements->maxAmountRequired,
            assetSymbol: $config->assetSymbol,
            assetDecimals: $config->assetDecimals,
            shopwareAmount: $amount,
            shopwareCurrency: $currency,
            expiresAt: $expiresAt,
        );
    }
}

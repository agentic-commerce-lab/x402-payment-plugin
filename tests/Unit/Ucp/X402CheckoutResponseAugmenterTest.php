<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Ucp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\X402Payments\Core\X402\Config\X402ConfigService;
use Swag\X402Payments\Tests\Unit\Support\X402Fixtures;
use Swag\X402Payments\Ucp\UcpBaseUriResolver;
use Swag\X402Payments\Ucp\X402CheckoutResponseAugmenter;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\OrderConfirmation;
use Ucp\Sdk\Model\RequestContext;

/**
 * Spec 7.2.0 / 26.4: the completed-checkout `extra.x402.pay_url` must be a
 * self-sufficient URL an agent can call as-is - the deepLinkCode ownership
 * proof travels as a query parameter rather than requiring the agent to know
 * to append it from the sibling `deep_link_code` field.
 */
#[CoversClass(X402CheckoutResponseAugmenter::class)]
final class X402CheckoutResponseAugmenterTest extends TestCase
{
    private const ORDER_ID = '0189aa';

    private EntityRepository&MockObject $orderRepository;

    private X402ConfigService&MockObject $configService;

    private X402CheckoutResponseAugmenter $augmenter;

    private Context $context;

    #[\Override]
    protected function setUp(): void
    {
        if (!interface_exists(\Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface::class)) {
            self::markTestSkipped('ucp-php-sdk is not installed in this repository; the augmenter bridge is optional.');
        }

        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->configService = $this->createMock(X402ConfigService::class);
        $this->context = Context::createDefaultContext();

        $this->augmenter = new X402CheckoutResponseAugmenter(
            $this->orderRepository,
            $this->configService,
            new UcpBaseUriResolver(),
        );
    }

    public function testPayUrlIncludesDeepLinkCodeQueryParameter(): void
    {
        $this->orderSearchReturns($this->order(deepLinkCode: 'abc123'));
        $this->configService->method('getConfig')->willReturn(X402Fixtures::config());

        $checkout = $this->augmenter->augment(
            $this->checkoutWithOrder(self::ORDER_ID),
            $this->requestContext('shop.test'),
        );

        $x402 = $checkout->extra['x402'];

        self::assertSame('https://shop.test/store-api/x402/order/0189aa/pay?deepLinkCode=abc123', $x402['pay_url']);
        // Back-compat: the discrete field is still present.
        self::assertSame('abc123', $x402['deep_link_code']);
    }

    public function testDeepLinkCodeIsUrlEncodedInPayUrl(): void
    {
        $this->orderSearchReturns($this->order(deepLinkCode: 'a b+c/d'));
        $this->configService->method('getConfig')->willReturn(X402Fixtures::config());

        $checkout = $this->augmenter->augment(
            $this->checkoutWithOrder(self::ORDER_ID),
            $this->requestContext('shop.test'),
        );

        self::assertSame(
            'https://shop.test/store-api/x402/order/0189aa/pay?deepLinkCode=' . rawurlencode('a b+c/d'),
            $checkout->extra['x402']['pay_url'],
        );
    }

    private function order(string $deepLinkCode): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setSalesChannelId(Uuid::randomHex());
        $order->setDeepLinkCode($deepLinkCode);

        return $order;
    }

    private function orderSearchReturns(OrderEntity $order): void
    {
        $this->orderRepository
            ->method('search')
            ->willReturn(
                new EntitySearchResult('order', 1, new OrderCollection([$order]), null, new Criteria(), $this->context),
            );
    }

    private function checkoutWithOrder(string $orderId): Checkout
    {
        return new Checkout(
            id: Uuid::randomHex(),
            status: CheckoutStatus::Completed,
            currency: 'USD',
            lineItems: [],
            totals: [],
            order: new OrderConfirmation($orderId),
        );
    }

    private function requestContext(string $host): RequestContext
    {
        return new RequestContext(host: $host, headers: ['host' => $host]);
    }
}

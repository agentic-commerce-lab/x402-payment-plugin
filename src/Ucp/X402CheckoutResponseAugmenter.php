<?php

declare(strict_types=1);

namespace Swag\X402Payments\Ucp;

use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Swag\X402Payments\Core\X402\Config\X402ConfigService;
use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;

/**
 * Attaches `extra.x402` to completed UCP checkouts so agents can pay the
 * placed order via the x402 402 handshake without out-of-band knowledge
 * (spec sections 7.2.0 and 26.4): the pay route URL and the order
 * deepLinkCode ownership proof, which UCP otherwise never exposes.
 *
 * The deepLinkCode goes to the same party UCP hands the orderId to - the
 * agent that completed this checkout - matching Shopware's own guest-order
 * semantics.
 */
class X402CheckoutResponseAugmenter implements CheckoutResponseAugmenterInterface
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly X402ConfigService $configService,
    ) {}

    public function augment(Checkout $checkout, RequestContext $context): Checkout
    {
        if ($checkout->order === null) {
            return $checkout;
        }

        $order = $this->loadOrder($checkout->order->id);
        if ($order === null || $order->getDeepLinkCode() === null) {
            return $checkout;
        }

        $config = $this->configService->getConfig($order->getSalesChannelId());
        $baseUri = rtrim($context->runtimeConfiguration?->baseUri ?? '', '/');
        if (!$config->isComplete() || $baseUri === '') {
            return $checkout;
        }

        $extra = [
            'handler_id' => X402UcpPaymentHandler::HANDLER_ID,
            'pay_url' => \sprintf('%s/store-api/x402/order/%s/pay', $baseUri, $order->getId()),
            'deep_link_code' => $order->getDeepLinkCode(),
            'scheme' => 'exact',
            'network' => $config->network,
            'asset' => $config->assetAddress,
            'asset_symbol' => $config->assetSymbol,
        ];

        // The Store API requires the sales channel access key header. It is
        // public client identification (embedded in every storefront page),
        // not a credential - without it a pure UCP agent could not call the
        // pay route.
        $accessKey = $order->getSalesChannel()?->getAccessKey();
        if ($accessKey !== null) {
            $extra['access_key'] = $accessKey;
        }

        return $this->withX402Extra($checkout, $extra);
    }

    private function loadOrder(string $orderId): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('salesChannel');

        $order = $this->orderRepository->search($criteria, Context::createDefaultContext())->getEntities()->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    /**
     * @param array<string, string> $x402
     */
    private function withX402Extra(Checkout $checkout, array $x402): Checkout
    {
        return new Checkout(
            $checkout->id,
            $checkout->status,
            $checkout->currency,
            $checkout->lineItems,
            $checkout->totals,
            $checkout->messages,
            $checkout->links,
            $checkout->buyer,
            $checkout->continueUrl,
            $checkout->expiresAt,
            $checkout->order,
            array_merge($checkout->extra, ['x402' => $x402]),
        );
    }
}

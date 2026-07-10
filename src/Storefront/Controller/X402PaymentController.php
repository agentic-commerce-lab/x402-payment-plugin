<?php

declare(strict_types=1);

namespace Swag\X402Payments\Storefront\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Swag\X402Payments\Core\X402\Config\X402ConfigService;
use Swag\X402Payments\StoreApi\X402PaymentSessionFactory;
use Swag\X402Payments\StoreApi\X402PayRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Storefront fallback wallet page (spec section 4.2 later scope): lets a
 * human buyer pay an open x402 order with a browser wallet. The actual
 * payment still runs through the Store API pay route - this page only
 * renders the requirements and signs them client-side.
 */
// The inherited $container/$twig are injected via setContainer()/setTwig()
// service calls, not the constructor (Symfony/Shopware controller pattern).
// @mago-expect analysis:uninitialized-property
#[Route(defaults: ['_routeScope' => ['storefront']])]
class X402PaymentController extends StorefrontController
{
    public function __construct(
        private readonly X402ConfigService $configService,
        private readonly X402PaymentSessionFactory $sessionFactory,
    ) {}

    #[Route(path: '/x402/pay/{orderId}', name: 'frontend.x402.pay', methods: ['GET'])]
    public function pay(string $orderId, Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $deepLinkCode = $request->query->getString('deepLinkCode');
        $config = $this->configService->getCompleteConfig($salesChannelContext->getSalesChannelId());

        $storeApiPayUrl = $this->generateUrl(
            'store-api.x402.order.pay',
            ['orderId' => $orderId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $session = $this->sessionFactory->prepare(
            $orderId,
            new X402PayRequest(
                deepLinkCode: $deepLinkCode !== '' ? $deepLinkCode : null,
                contextTokenHash: hash('sha256', $salesChannelContext->getToken()),
                deepLinkCodeHash: $deepLinkCode !== '' ? hash('sha256', $deepLinkCode) : null,
                idempotencyKeyHash: hash('sha256', 'storefront'),
                paymentHeader: null,
                resourceUrl: $storeApiPayUrl,
            ),
            $config,
            $salesChannelContext,
        );

        return $this->renderStorefront('@SwagX402Payments/storefront/page/x402/pay.html.twig', [
            'x402Session' => $session,
            'x402Requirements' => $session->getRequirementsJson(),
            'x402PayUrl' => $storeApiPayUrl,
            'x402DeepLinkCode' => $deepLinkCode,
            'x402AccessKey' => $salesChannelContext->getSalesChannel()->getAccessKey(),
        ]);
    }
}

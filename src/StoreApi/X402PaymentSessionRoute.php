<?php

declare(strict_types=1);

namespace Swag\X402Payments\StoreApi;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\X402Payments\Core\X402\Exception\X402Exception;
use Swag\X402Payments\Core\X402\X402PaymentSessionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Polling endpoint for delayed settlement and agent recovery (spec 7.3).
 */
#[Route(defaults: ['_routeScope' => ['store-api']])]
class X402PaymentSessionRoute
{
    public function __construct(
        private readonly X402PaymentSessionService $sessionService,
        private readonly X402PaymentResponseFactory $responseFactory,
    ) {}

    #[Route(
        path: '/store-api/x402/payment-session/{paymentSessionId}',
        name: 'store-api.x402.payment-session',
        methods: ['GET'],
    )]
    public function status(string $paymentSessionId, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $session = $this->sessionService->get($paymentSessionId, $salesChannelContext->getContext());

        if ($session->getSalesChannelId() !== $salesChannelContext->getSalesChannelId()) {
            throw X402Exception::sessionNotFound($paymentSessionId);
        }

        return $this->responseFactory->sessionStatusResponse($session);
    }
}

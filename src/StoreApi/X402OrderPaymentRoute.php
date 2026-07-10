<?php

declare(strict_types=1);

namespace Swag\X402Payments\StoreApi;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\X402Payments\Core\X402\Config\X402ConfigService;
use Swag\X402Payments\Core\X402\X402PayloadParser;
use Swag\X402Payments\Core\X402\X402SettlementService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Canonical headless x402 endpoint (spec section 7.2): without an X-PAYMENT
 * header it answers HTTP 402 with the payment requirements; with the header
 * it verifies and settles the payment and marks the transaction paid.
 */
#[Route(defaults: ['_routeScope' => ['store-api']])]
class X402OrderPaymentRoute
{
    public function __construct(
        private readonly X402ConfigService $configService,
        private readonly X402PaymentSessionFactory $sessionFactory,
        private readonly X402PayloadParser $payloadParser,
        private readonly X402SettlementService $settlementService,
        private readonly X402PaymentResponseFactory $responseFactory,
    ) {}

    /**
     * @throws \Throwable settlement failures surface as Store API error responses
     */
    #[Route(path: '/store-api/x402/order/{orderId}/pay', name: 'store-api.x402.order.pay', methods: ['POST'])]
    public function pay(string $orderId, Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $config = $this->configService->getCompleteConfig($salesChannelContext->getSalesChannelId());
        $payRequest = X402PayRequest::fromHttpRequest($request);

        $session = $this->sessionFactory->prepare($orderId, $payRequest, $config, $salesChannelContext);

        if ($session->isSettled()) {
            return $this->responseFactory->paidResponse($session);
        }

        if ($payRequest->paymentHeader === null) {
            return $this->responseFactory->requirementsResponse($session);
        }

        $payload = $this->payloadParser->parse($payRequest->paymentHeader);
        $settledSession = $this->settlementService->settle(
            $session,
            $payload,
            $config,
            $salesChannelContext->getContext(),
        );

        return $this->responseFactory->paidResponse($settledSession);
    }
}

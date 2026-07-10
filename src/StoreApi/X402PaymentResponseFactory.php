<?php

declare(strict_types=1);

namespace Swag\X402Payments\StoreApi;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the protocol responses of spec section 7. Only opaque ids, the order
 * number, and payment data appear here - never customer PII (spec 15.3).
 */
class X402PaymentResponseFactory
{
    public function requirementsResponse(X402PaymentSessionEntity $session): JsonResponse
    {
        return new JsonResponse([
            'x402Version' => 1,
            'error' => 'X-PAYMENT header is required',
            'accepts' => [$session->getRequirementsJson()],
            'shopware' => [
                'orderId' => $session->getOrderId(),
                'orderNumber' => $session->getOrderNumber(),
                'orderTransactionId' => $session->getOrderTransactionId(),
                'paymentSessionId' => $session->getId(),
                'amount' => [
                    'currency' => $session->getShopwareCurrency(),
                    'totalPrice' => $session->getShopwareAmount(),
                ],
                'expiresAt' => $session->getExpiresAt()->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_PAYMENT_REQUIRED);
    }

    public function paidResponse(X402PaymentSessionEntity $session): JsonResponse
    {
        $response = new JsonResponse([
            'status' => 'paid',
            'orderId' => $session->getOrderId(),
            'orderNumber' => $session->getOrderNumber(),
            'orderTransactionId' => $session->getOrderTransactionId(),
            'transactionState' => OrderTransactionStates::STATE_PAID,
            'payment' => [
                'paymentSessionId' => $session->getId(),
                'scheme' => $session->getScheme(),
                'network' => $session->getNetwork(),
                'asset' => $session->getAssetSymbol(),
                'amountAtomic' => $session->getPaymentAmountAtomic(),
                'payer' => $session->getPayerWallet(),
                'transactionHash' => $session->getSettlementTransactionHash(),
            ],
        ]);

        $settleResponse = $session->getSettleResponseJson();
        if ($settleResponse !== null) {
            $encoded = json_encode($settleResponse);
            if ($encoded !== false) {
                $response->headers->set('X-PAYMENT-RESPONSE', base64_encode($encoded));
            }
        }

        return $response;
    }

    public function sessionStatusResponse(X402PaymentSessionEntity $session): JsonResponse
    {
        return new JsonResponse([
            'paymentSessionId' => $session->getId(),
            'status' => $session->getState(),
            'orderId' => $session->getOrderId(),
            'orderNumber' => $session->getOrderNumber(),
            'orderTransactionId' => $session->getOrderTransactionId(),
            'network' => $session->getNetwork(),
            'transactionHash' => $session->getSettlementTransactionHash(),
            'expiresAt' => $session->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'paidAt' => $session->getPaidAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}

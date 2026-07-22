<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

// Shopware exception factory pattern: one method per error code.
// @mago-expect lint:too-many-methods
class X402Exception extends HttpException
{
    public const NOT_CONFIGURED = 'SWAG_X402__NOT_CONFIGURED';
    public const ORDER_NOT_FOUND = 'SWAG_X402__ORDER_NOT_FOUND';
    public const OWNERSHIP_PROOF_MISSING = 'SWAG_X402__OWNERSHIP_PROOF_MISSING';
    public const PAYMENT_METHOD_NOT_SELECTED = 'SWAG_X402__PAYMENT_METHOD_NOT_SELECTED';
    public const TRANSACTION_NOT_PAYABLE = 'SWAG_X402__TRANSACTION_NOT_PAYABLE';
    public const AMOUNT_OUT_OF_BOUNDS = 'SWAG_X402__AMOUNT_OUT_OF_BOUNDS';
    public const CURRENCY_NOT_SUPPORTED = 'SWAG_X402__CURRENCY_NOT_SUPPORTED';
    public const INVALID_PAYMENT_HEADER = 'SWAG_X402__INVALID_PAYMENT_HEADER';
    public const PAYLOAD_MISMATCH = 'SWAG_X402__PAYLOAD_MISMATCH';
    public const PAYLOAD_REPLAYED = 'SWAG_X402__PAYLOAD_REPLAYED';
    public const QUOTE_EXPIRED = 'SWAG_X402__QUOTE_EXPIRED';
    public const VERIFICATION_FAILED = 'SWAG_X402__VERIFICATION_FAILED';
    public const SETTLEMENT_FAILED = 'SWAG_X402__SETTLEMENT_FAILED';
    public const FACILITATOR_UNAVAILABLE = 'SWAG_X402__FACILITATOR_UNAVAILABLE';
    public const SESSION_NOT_FOUND = 'SWAG_X402__SESSION_NOT_FOUND';
    public const CONCURRENT_SETTLEMENT = 'SWAG_X402__CONCURRENT_SETTLEMENT';

    public static function notConfigured(string $salesChannelId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::NOT_CONFIGURED,
            'The x402 payment method is not fully configured for sales channel "{{ salesChannelId }}".',
            ['salesChannelId' => $salesChannelId],
        );
    }

    public static function orderNotFound(string $orderId): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::ORDER_NOT_FOUND,
            'Order "{{ orderId }}" was not found or has no open transaction.',
            ['orderId' => $orderId],
        );
    }

    public static function ownershipProofMissing(): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::OWNERSHIP_PROOF_MISSING,
            'No valid order ownership proof was provided. Send the context token that placed the order or the order deepLinkCode.',
        );
    }

    public static function paymentMethodNotSelected(): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::PAYMENT_METHOD_NOT_SELECTED,
            'The order transaction does not use the x402 payment method and after-order switching is disabled.',
        );
    }

    public static function transactionNotPayable(string $state): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::TRANSACTION_NOT_PAYABLE,
            'The order transaction is in state "{{ state }}" and cannot be paid.',
            ['state' => $state],
        );
    }

    public static function amountOutOfBounds(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::AMOUNT_OUT_OF_BOUNDS,
            'The order amount is outside the configured x402 payment limits.',
        );
    }

    public static function currencyNotSupported(string $currency): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::CURRENCY_NOT_SUPPORTED,
            'Orders in currency "{{ currency }}" cannot be paid with x402 on this sales channel.',
            ['currency' => $currency],
        );
    }

    public static function invalidPaymentHeader(string $reason): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_PAYMENT_HEADER,
            'The X-PAYMENT header is invalid: {{ reason }}',
            ['reason' => $reason],
        );
    }

    public static function payloadMismatch(string $reason): self
    {
        return new self(
            Response::HTTP_PAYMENT_REQUIRED,
            self::PAYLOAD_MISMATCH,
            'The payment payload does not match the payment requirements: {{ reason }}',
            ['reason' => $reason],
        );
    }

    public static function payloadReplayed(): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::PAYLOAD_REPLAYED,
            'This payment payload was already used for another payment.',
        );
    }

    public static function quoteExpired(string $paymentSessionId): self
    {
        return new self(
            Response::HTTP_PAYMENT_REQUIRED,
            self::QUOTE_EXPIRED,
            'The payment requirements expired. Request new requirements.',
            ['paymentSessionId' => $paymentSessionId],
        );
    }

    /**
     * @param array<string, mixed> $domain advertised EIP-712 domain + settlement target
     *                                      (name, version, network, asset) for agent self-diagnosis
     */
    public static function verificationFailed(string $reason, array $domain = []): self
    {
        $parameters = ['reason' => $reason];
        if ($domain !== []) {
            $parameters['domain'] = $domain;
        }

        return new self(
            Response::HTTP_PAYMENT_REQUIRED,
            self::VERIFICATION_FAILED,
            'The facilitator rejected the payment payload: {{ reason }}',
            $parameters,
        );
    }

    public static function settlementFailed(string $reason): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::SETTLEMENT_FAILED,
            'The payment could not be settled: {{ reason }}',
            ['reason' => $reason],
        );
    }

    public static function facilitatorUnavailable(): self
    {
        return new self(
            Response::HTTP_BAD_GATEWAY,
            self::FACILITATOR_UNAVAILABLE,
            'The x402 facilitator is not reachable or returned an invalid response.',
        );
    }

    public static function sessionNotFound(string $paymentSessionId): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::SESSION_NOT_FOUND,
            'Payment session "{{ paymentSessionId }}" was not found.',
            ['paymentSessionId' => $paymentSessionId],
        );
    }

    public static function concurrentSettlement(): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::CONCURRENT_SETTLEMENT,
            'Another settlement for this payment session is in progress. Retry with the same Idempotency-Key.',
        );
    }
}

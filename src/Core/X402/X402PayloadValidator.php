<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402;

use Psr\Clock\ClockInterface;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Dto\PaymentPayload;
use Swag\X402Payments\Core\X402\Exception\X402Exception;

class X402PayloadValidator
{
    public function __construct(
        private readonly X402AmountConverter $amountConverter,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Local binding checks before any facilitator call (spec section 12.3).
     * The facilitator still performs cryptographic verification.
     */
    public function validate(PaymentPayload $payload, X402PaymentSessionEntity $session): void
    {
        $now = $this->clock->now();

        if ($session->isExpired($now)) {
            throw X402Exception::quoteExpired($session->getId());
        }

        if (!\in_array($session->getState(), X402PaymentSessionStates::PAYABLE_STATES, true)) {
            throw X402Exception::transactionNotPayable($session->getState());
        }

        $this->validateBinding($payload, $session);
        $this->validateAuthorizationWindow($payload, $now);
    }

    private function validateBinding(PaymentPayload $payload, X402PaymentSessionEntity $session): void
    {
        if ($payload->x402Version !== 1) {
            throw X402Exception::payloadMismatch('unsupported x402Version');
        }

        if ($payload->scheme !== $session->getScheme()) {
            throw X402Exception::payloadMismatch('scheme does not match payment requirements');
        }

        if ($payload->network !== $session->getNetwork()) {
            throw X402Exception::payloadMismatch('network does not match payment requirements');
        }

        if (!hash_equals(strtolower($session->getPayTo()), strtolower($payload->to))) {
            throw X402Exception::payloadMismatch('authorization.to does not match the merchant wallet');
        }

        if (!$this->amountConverter->isAtLeast($payload->value, $session->getPaymentAmountAtomic())) {
            throw X402Exception::payloadMismatch('authorization.value is below the required amount');
        }
    }

    private function validateAuthorizationWindow(PaymentPayload $payload, \DateTimeImmutable $now): void
    {
        $timestamp = $now->getTimestamp();

        if ($payload->validBefore <= $timestamp) {
            throw X402Exception::payloadMismatch('authorization.validBefore is in the past');
        }

        if ($payload->validAfter > $timestamp) {
            throw X402Exception::payloadMismatch('authorization.validAfter is in the future');
        }
    }
}

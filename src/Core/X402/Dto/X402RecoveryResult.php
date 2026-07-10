<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402\Dto;

use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;

final readonly class X402RecoveryResult
{
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_RECOVERABLE = 'recoverable';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    private function __construct(
        public string $paymentSessionId,
        public string $orderTransactionId,
        public string $orderNumber,
        public string $status,
        public string $detail,
    ) {}

    public static function recovered(X402PaymentSessionEntity $session, string $previousState): self
    {
        return self::fromSession($session, self::STATUS_RECOVERED, 'was ' . $previousState . ', now paid');
    }

    public static function recoverable(X402PaymentSessionEntity $session, string $currentState): self
    {
        return self::fromSession($session, self::STATUS_RECOVERABLE, 'currently ' . $currentState . ' (dry run)');
    }

    public static function skipped(X402PaymentSessionEntity $session, string $reason): self
    {
        return self::fromSession($session, self::STATUS_SKIPPED, $reason);
    }

    public static function failed(X402PaymentSessionEntity $session, string $reason): self
    {
        return self::fromSession($session, self::STATUS_FAILED, $reason);
    }

    public function needsAttention(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    private static function fromSession(X402PaymentSessionEntity $session, string $status, string $detail): self
    {
        return new self(
            paymentSessionId: $session->getId(),
            orderTransactionId: $session->getOrderTransactionId(),
            orderNumber: $session->getOrderNumber(),
            status: $status,
            detail: $detail,
        );
    }
}

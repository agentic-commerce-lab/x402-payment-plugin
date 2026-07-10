<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402\Dto;

final readonly class X402Quote
{
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        public PaymentRequirements $requirements,
        public string $quoteHash,
        public string $atomicAmount,
        public string $assetSymbol,
        public int $assetDecimals,
        public float $shopwareAmount,
        public string $shopwareCurrency,
        public \DateTimeImmutable $expiresAt,
    ) {}
}

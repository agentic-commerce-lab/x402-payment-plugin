<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Support;

use Psr\Clock\ClockInterface;

final readonly class FixedClock implements ClockInterface
{
    public function __construct(
        private \DateTimeImmutable $now,
    ) {}

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}

<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402\Dto;

final readonly class PaymentPayload
{
    /**
     * @param array<string, mixed> $raw
     */
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        public int $x402Version,
        public string $scheme,
        public string $network,
        public string $signature,
        public string $from,
        public string $to,
        public string $value,
        public int $validAfter,
        public int $validBefore,
        public string $nonce,
        public string $payloadHash,
        public array $raw,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toFacilitatorArray(): array
    {
        return $this->raw;
    }
}

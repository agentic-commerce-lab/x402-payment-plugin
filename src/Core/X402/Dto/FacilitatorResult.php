<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402\Dto;

final readonly class FacilitatorResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public bool $success,
        public ?string $payer,
        public ?string $transactionHash,
        public ?string $errorReason,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromVerifyResponse(array $data): self
    {
        return new self(
            success: (bool) ($data['isValid'] ?? false),
            payer: self::stringOrNull($data['payer'] ?? null),
            transactionHash: null,
            errorReason: self::stringOrNull($data['invalidReason'] ?? null),
            raw: $data,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromSettleResponse(array $data): self
    {
        return new self(
            success: (bool) ($data['success'] ?? false),
            payer: self::stringOrNull($data['payer'] ?? null),
            transactionHash: self::stringOrNull($data['transaction'] ?? $data['txHash'] ?? null),
            errorReason: self::stringOrNull($data['errorReason'] ?? null),
            raw: $data,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}

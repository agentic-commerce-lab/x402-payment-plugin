<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402\Dto;

final readonly class PaymentRequirements
{
    /**
     * @param array<string, mixed> $extra
     */
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        public string $scheme,
        public string $network,
        public string $maxAmountRequired,
        public string $asset,
        public string $payTo,
        public string $resource,
        public string $description,
        public int $maxTimeoutSeconds,
        public array $extra,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scheme' => $this->scheme,
            'network' => $this->network,
            'maxAmountRequired' => $this->maxAmountRequired,
            'asset' => $this->asset,
            'payTo' => $this->payTo,
            'resource' => $this->resource,
            'description' => $this->description,
            'mimeType' => 'application/json',
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
            'extra' => $this->extra,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $extra */
        $extra = \is_array($data['extra'] ?? null) ? $data['extra'] : [];

        return new self(
            scheme: (string) ($data['scheme'] ?? ''),
            network: (string) ($data['network'] ?? ''),
            maxAmountRequired: (string) ($data['maxAmountRequired'] ?? ''),
            asset: (string) ($data['asset'] ?? ''),
            payTo: (string) ($data['payTo'] ?? ''),
            resource: (string) ($data['resource'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            maxTimeoutSeconds: (int) ($data['maxTimeoutSeconds'] ?? 0),
            extra: $extra,
        );
    }
}

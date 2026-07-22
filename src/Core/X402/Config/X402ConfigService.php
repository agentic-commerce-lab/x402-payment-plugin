<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\X402Payments\Core\X402\Exception\X402Exception;

class X402ConfigService
{
    private const DOMAIN = 'SwagX402Payments.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {}

    public function getConfig(?string $salesChannelId): X402Config
    {
        return new X402Config(
            enabled: $this->bool('enabled', $salesChannelId, false),
            facilitatorBaseUrl: rtrim($this->string('facilitatorBaseUrl', $salesChannelId), '/'),
            facilitatorApiKey: $this->stringOrNull('facilitatorApiKey', $salesChannelId),
            merchantWalletAddress: $this->string('merchantWalletAddress', $salesChannelId),
            network: $this->string('network', $salesChannelId, 'base'),
            assetAddress: $this->string('assetAddress', $salesChannelId),
            assetSymbol: $this->string('assetSymbol', $salesChannelId, 'USDC'),
            assetDecimals: $this->int('assetDecimals', $salesChannelId, 6),
            supportedCurrency: $this->string('supportedCurrency', $salesChannelId, 'EUR'),
            minOrderAmount: $this->float('minOrderAmount', $salesChannelId, 0.5),
            maxOrderAmount: $this->float('maxOrderAmount', $salesChannelId, 1000.0),
            maxTimeoutSeconds: $this->int('maxTimeoutSeconds', $salesChannelId, 300),
            facilitatorTimeoutSeconds: $this->int('facilitatorTimeoutSeconds', $salesChannelId, 15),
            sessionExpiryMinutes: $this->int('sessionExpiryMinutes', $salesChannelId, 30),
            allowDeepLinkOwnershipProof: $this->bool('allowDeepLinkOwnershipProof', $salesChannelId, true),
            allowAfterOrderPaymentMethodSwitch: $this->bool(
                'allowAfterOrderPaymentMethodSwitch',
                $salesChannelId,
                true,
            ),
            assetEip712Name: $this->string('assetEip712Name', $salesChannelId, ''),
        );
    }

    public function getCompleteConfig(string $salesChannelId): X402Config
    {
        $config = $this->getConfig($salesChannelId);
        if (!$config->isComplete()) {
            throw X402Exception::notConfigured($salesChannelId);
        }

        return $config;
    }

    private function string(string $key, ?string $salesChannelId, string $default = ''): string
    {
        $value = $this->systemConfigService->getString(self::DOMAIN . $key, $salesChannelId);

        return $value !== '' ? $value : $default;
    }

    private function stringOrNull(string $key, ?string $salesChannelId): ?string
    {
        $value = $this->systemConfigService->getString(self::DOMAIN . $key, $salesChannelId);

        return $value !== '' ? $value : null;
    }

    private function int(string $key, ?string $salesChannelId, int $default): int
    {
        $value = $this->systemConfigService->getInt(self::DOMAIN . $key, $salesChannelId);

        return $value !== 0 ? $value : $default;
    }

    private function float(string $key, ?string $salesChannelId, float $default): float
    {
        $value = $this->systemConfigService->getFloat(self::DOMAIN . $key, $salesChannelId);

        return $value !== 0.0 ? $value : $default;
    }

    private function bool(string $key, ?string $salesChannelId, bool $default): bool
    {
        $raw = $this->systemConfigService->get(self::DOMAIN . $key, $salesChannelId);
        if ($raw === null) {
            return $default;
        }

        return (bool) $raw;
    }
}

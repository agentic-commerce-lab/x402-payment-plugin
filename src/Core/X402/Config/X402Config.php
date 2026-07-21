<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402\Config;

final readonly class X402Config
{
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        public bool $enabled,
        public string $facilitatorBaseUrl,
        #[\SensitiveParameter]
        public ?string $facilitatorApiKey,
        public string $merchantWalletAddress,
        public string $network,
        public string $assetAddress,
        public string $assetSymbol,
        public int $assetDecimals,
        public string $supportedCurrency,
        public float $minOrderAmount,
        public float $maxOrderAmount,
        public int $maxTimeoutSeconds,
        public int $facilitatorTimeoutSeconds,
        public int $sessionExpiryMinutes,
        public bool $allowDeepLinkOwnershipProof,
        public bool $allowAfterOrderPaymentMethodSwitch,
        // Coinbase CDP facilitator auth (mainnet). When both are set, the facilitator
        // client signs a per-request CDP JWT instead of sending facilitatorApiKey as a
        // static bearer. keyId = CDP API key name/id; keySecret = its Ed25519 secret.
        public ?string $facilitatorCdpKeyId = null,
        #[\SensitiveParameter]
        public ?string $facilitatorCdpKeySecret = null,
    ) {}

    public function isComplete(): bool
    {
        return (
            $this->enabled
            && $this->facilitatorBaseUrl !== ''
            && $this->merchantWalletAddress !== ''
            && $this->network !== ''
            && $this->assetAddress !== ''
            && $this->assetSymbol !== ''
        );
    }
}

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
        // EIP-712 domain `name` for the ERC-3009 TransferWithAuthorization signature.
        // MUST equal the asset contract's on-chain name() (Base mainnet USDC = "USD Coin",
        // testnet USDC = "USDC"). Empty falls back to $assetSymbol for backward compat.
        public string $assetEip712Name = '',
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

<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Content\X402PaymentSession;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

// DAL entity mirroring the swag_x402_payment_session table (spec section 9.1);
// properties are hydrated by the DAL, not a constructor.
// @mago-expect lint:too-many-properties
// @mago-expect analysis:missing-constructor
class X402PaymentSessionEntity extends Entity
{
    use EntityIdTrait;

    protected string $salesChannelId;

    protected ?string $contextTokenHash = null;

    protected string $ownershipProof;

    protected ?string $deepLinkCodeHash = null;

    protected string $orderId;

    protected string $orderTransactionId;

    protected string $orderNumber;

    protected string $state;

    protected string $scheme;

    protected string $network;

    protected string $asset;

    protected string $assetSymbol;

    protected int $assetDecimals;

    protected string $shopwareCurrency;

    protected float $shopwareAmount;

    protected string $paymentAmountAtomic;

    protected string $payTo;

    protected string $resourceUrl;

    protected string $quoteHash;

    /**
     * @var array<string, mixed>
     */
    protected array $requirementsJson = [];

    protected ?string $paymentPayloadHash = null;

    protected ?string $payerWallet = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $verifyResponseJson = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $settleResponseJson = null;

    protected ?string $settlementTransactionHash = null;

    protected string $idempotencyKeyHash;

    protected \DateTimeInterface $expiresAt;

    protected ?\DateTimeInterface $paidAt = null;

    protected ?string $failureReason = null;

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function getContextTokenHash(): ?string
    {
        return $this->contextTokenHash;
    }

    public function getOwnershipProof(): string
    {
        return $this->ownershipProof;
    }

    public function getDeepLinkCodeHash(): ?string
    {
        return $this->deepLinkCodeHash;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getOrderTransactionId(): string
    {
        return $this->orderTransactionId;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getNetwork(): string
    {
        return $this->network;
    }

    public function getAsset(): string
    {
        return $this->asset;
    }

    public function getAssetSymbol(): string
    {
        return $this->assetSymbol;
    }

    public function getAssetDecimals(): int
    {
        return $this->assetDecimals;
    }

    public function getShopwareCurrency(): string
    {
        return $this->shopwareCurrency;
    }

    public function getShopwareAmount(): float
    {
        return $this->shopwareAmount;
    }

    public function getPaymentAmountAtomic(): string
    {
        return $this->paymentAmountAtomic;
    }

    public function getPayTo(): string
    {
        return $this->payTo;
    }

    public function getResourceUrl(): string
    {
        return $this->resourceUrl;
    }

    public function getQuoteHash(): string
    {
        return $this->quoteHash;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequirementsJson(): array
    {
        return $this->requirementsJson;
    }

    public function getPaymentPayloadHash(): ?string
    {
        return $this->paymentPayloadHash;
    }

    public function getPayerWallet(): ?string
    {
        return $this->payerWallet;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVerifyResponseJson(): ?array
    {
        return $this->verifyResponseJson;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSettleResponseJson(): ?array
    {
        return $this->settleResponseJson;
    }

    public function getSettlementTransactionHash(): ?string
    {
        return $this->settlementTransactionHash;
    }

    public function getIdempotencyKeyHash(): string
    {
        return $this->idempotencyKeyHash;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function getPaidAt(): ?\DateTimeInterface
    {
        return $this->paidAt;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function isExpired(\DateTimeInterface $now): bool
    {
        return $this->expiresAt < $now;
    }

    public function isSettled(): bool
    {
        return $this->state === X402PaymentSessionStates::STATE_SETTLED;
    }
}

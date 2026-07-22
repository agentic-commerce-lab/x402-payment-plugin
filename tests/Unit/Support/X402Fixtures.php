<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Support;

use Shopware\Core\Framework\Uuid\Uuid;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Config\X402Config;
use Swag\X402Payments\Core\X402\Dto\PaymentPayload;
use Swag\X402Payments\Core\X402\Dto\PaymentRequirements;

/**
 * Deterministic fixtures shared by the unit suite. Payload and session
 * defaults are consistent with each other, so a default payload validates
 * against a default session; tests override single fields to break exactly
 * one binding at a time.
 */
final class X402Fixtures
{
    public const NOW = '2026-01-01T12:00:00+00:00';
    public const MERCHANT_WALLET = '0x1111111111111111111111111111111111111111';
    public const PAYER_WALLET = '0x2222222222222222222222222222222222222222';
    public const ASSET_ADDRESS = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';
    public const ATOMIC_AMOUNT = '42990000';
    public const NETWORK = 'base-sepolia';
    public const RESOURCE_URL = 'https://shop.test/store-api/x402/order/0189aa/pay';

    private function __construct() {}

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    public static function clock(): FixedClock
    {
        return new FixedClock(self::now());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function session(array $overrides = []): X402PaymentSessionEntity
    {
        $session = new X402PaymentSessionEntity();
        $id = Uuid::randomHex();

        $session->assign(array_merge([
            'id' => $id,
            '_uniqueIdentifier' => $id,
            'salesChannelId' => Uuid::randomHex(),
            'ownershipProof' => X402PaymentSessionStates::OWNERSHIP_PROOF_CONTEXT_TOKEN,
            'orderId' => Uuid::randomHex(),
            'orderTransactionId' => Uuid::randomHex(),
            'orderNumber' => '10042',
            'state' => X402PaymentSessionStates::STATE_REQUIREMENTS_ISSUED,
            'scheme' => 'exact',
            'network' => self::NETWORK,
            'asset' => self::ASSET_ADDRESS,
            'assetSymbol' => 'USDC',
            'assetDecimals' => 6,
            'shopwareCurrency' => 'USD',
            'shopwareAmount' => 42.99,
            'paymentAmountAtomic' => self::ATOMIC_AMOUNT,
            'payTo' => self::MERCHANT_WALLET,
            'resourceUrl' => self::RESOURCE_URL,
            'quoteHash' => hash('sha256', 'fixture-quote'),
            'requirementsJson' => self::requirements()->toArray(),
            'idempotencyKeyHash' => hash('sha256', 'fixture-idempotency-key'),
            'expiresAt' => self::now()->modify('+5 minutes'),
        ], $overrides));

        return $session;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function payload(array $overrides = []): PaymentPayload
    {
        $values = array_merge([
            'x402Version' => 1,
            'scheme' => 'exact',
            'network' => self::NETWORK,
            'signature' => '0x' . str_repeat('ab', 65),
            'from' => self::PAYER_WALLET,
            'to' => self::MERCHANT_WALLET,
            'value' => self::ATOMIC_AMOUNT,
            'validAfter' => 0,
            'validBefore' => self::now()->modify('+4 minutes')->getTimestamp(),
            'nonce' => '0x' . str_repeat('cd', 32),
            'payloadHash' => hash('sha256', 'fixture-payload'),
            'raw' => ['x402Version' => 1, 'scheme' => 'exact', 'network' => self::NETWORK],
        ], $overrides);

        return new PaymentPayload(
            x402Version: $values['x402Version'],
            scheme: $values['scheme'],
            network: $values['network'],
            signature: $values['signature'],
            from: $values['from'],
            to: $values['to'],
            value: $values['value'],
            validAfter: $values['validAfter'],
            validBefore: $values['validBefore'],
            nonce: $values['nonce'],
            payloadHash: $values['payloadHash'],
            raw: $values['raw'],
        );
    }

    public static function requirements(): PaymentRequirements
    {
        return new PaymentRequirements(
            scheme: 'exact',
            network: self::NETWORK,
            maxAmountRequired: self::ATOMIC_AMOUNT,
            asset: self::ASSET_ADDRESS,
            payTo: self::MERCHANT_WALLET,
            resource: self::RESOURCE_URL,
            description: 'Shopware order 10042',
            maxTimeoutSeconds: 300,
            extra: ['name' => 'USDC', 'version' => '2'],
        );
    }

    public static function config(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        string $assetEip712Name = '',
    ): X402Config {
        return new X402Config(
            enabled: true,
            facilitatorBaseUrl: 'https://facilitator.test',
            facilitatorApiKey: $apiKey,
            merchantWalletAddress: self::MERCHANT_WALLET,
            network: self::NETWORK,
            assetAddress: self::ASSET_ADDRESS,
            assetSymbol: 'USDC',
            assetDecimals: 6,
            supportedCurrency: 'USD',
            minOrderAmount: 0.5,
            maxOrderAmount: 1000.0,
            maxTimeoutSeconds: 300,
            facilitatorTimeoutSeconds: 10,
            sessionExpiryMinutes: 15,
            allowDeepLinkOwnershipProof: true,
            allowAfterOrderPaymentMethodSwitch: true,
            assetEip712Name: $assetEip712Name,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function encodePaymentHeader(array $data): string
    {
        return base64_encode(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $authorizationOverrides
     *
     * @return array<string, mixed>
     */
    public static function paymentHeaderData(array $authorizationOverrides = []): array
    {
        return [
            'x402Version' => 1,
            'scheme' => 'exact',
            'network' => self::NETWORK,
            'payload' => [
                'signature' => '0x' . str_repeat('ab', 65),
                'authorization' => array_merge([
                    'from' => self::PAYER_WALLET,
                    'to' => self::MERCHANT_WALLET,
                    'value' => self::ATOMIC_AMOUNT,
                    'validAfter' => 0,
                    'validBefore' => self::now()->modify('+4 minutes')->getTimestamp(),
                    'nonce' => '0x' . str_repeat('cd', 32),
                ], $authorizationOverrides),
            ],
        ];
    }
}

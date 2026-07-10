<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Core\X402;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\X402Payments\Core\X402\Dto\PaymentRequirements;
use Swag\X402Payments\Core\X402\X402QuoteHasher;
use Swag\X402Payments\Tests\Unit\Support\X402Fixtures;

#[CoversClass(X402QuoteHasher::class)]
final class X402QuoteHasherTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0189aabbccdd11223344556677889900';
    private const ORDER_TRANSACTION_ID = '0189ffeeddcc11223344556677889900';
    private const EXPIRES_AT = '2026-01-01T12:05:00+00:00';

    public function testHashIsDeterministic(): void
    {
        $hasher = new X402QuoteHasher();

        $first = $this->hash($hasher, X402Fixtures::requirements());
        $second = $this->hash($hasher, X402Fixtures::requirements());

        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    }

    /**
     * Spec section 11: the quote hash binds the exact transaction, amount,
     * asset, network, merchant wallet, resource URL, and expiry. Changing any
     * bound input must change the hash.
     */
    public function testEveryBoundInputChangesTheHash(): void
    {
        $hasher = new X402QuoteHasher();
        $baseline = $this->hash($hasher, X402Fixtures::requirements());

        $variants = [
            'salesChannelId' => $hasher->hash(
                'ffffffffffffffffffffffffffffffff',
                self::ORDER_TRANSACTION_ID,
                X402Fixtures::requirements(),
                self::EXPIRES_AT,
            ),
            'orderTransactionId' => $hasher->hash(
                self::SALES_CHANNEL_ID,
                'ffffffffffffffffffffffffffffffff',
                X402Fixtures::requirements(),
                self::EXPIRES_AT,
            ),
            'expiresAt' => $hasher->hash(
                self::SALES_CHANNEL_ID,
                self::ORDER_TRANSACTION_ID,
                X402Fixtures::requirements(),
                '2026-01-01T12:06:00+00:00',
            ),
            'amount' => $this->hash($hasher, $this->requirementsWith(maxAmountRequired: '1')),
            'network' => $this->hash($hasher, $this->requirementsWith(network: 'base')),
            'payTo' => $this->hash($hasher, $this->requirementsWith(payTo: X402Fixtures::PAYER_WALLET)),
            'asset' => $this->hash($hasher, $this->requirementsWith(asset: '0x' . str_repeat('9', 40))),
            'resource' => $this->hash($hasher, $this->requirementsWith(resource: 'https://evil.test/pay')),
            'scheme' => $this->hash($hasher, $this->requirementsWith(scheme: 'upto')),
        ];

        foreach ($variants as $field => $variantHash) {
            self::assertNotSame($baseline, $variantHash, \sprintf('changing "%s" must change the quote hash', $field));
        }
    }

    public function testHashOpaqueIsSha256(): void
    {
        $hasher = new X402QuoteHasher();

        self::assertSame(hash('sha256', 'context-token'), $hasher->hashOpaque('context-token'));
    }

    private function hash(X402QuoteHasher $hasher, PaymentRequirements $requirements): string
    {
        return $hasher->hash(self::SALES_CHANNEL_ID, self::ORDER_TRANSACTION_ID, $requirements, self::EXPIRES_AT);
    }

    // Mirrors the PaymentRequirements constructor so each bound field can vary.
    // @mago-expect lint:excessive-parameter-list
    private function requirementsWith(
        ?string $scheme = null,
        ?string $network = null,
        ?string $maxAmountRequired = null,
        ?string $asset = null,
        ?string $payTo = null,
        ?string $resource = null,
    ): PaymentRequirements {
        $base = X402Fixtures::requirements();

        return new PaymentRequirements(
            scheme: $scheme ?? $base->scheme,
            network: $network ?? $base->network,
            maxAmountRequired: $maxAmountRequired ?? $base->maxAmountRequired,
            asset: $asset ?? $base->asset,
            payTo: $payTo ?? $base->payTo,
            resource: $resource ?? $base->resource,
            description: $base->description,
            maxTimeoutSeconds: $base->maxTimeoutSeconds,
            extra: $base->extra,
        );
    }
}

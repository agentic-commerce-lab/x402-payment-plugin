<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Core\X402;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Exception\X402Exception;
use Swag\X402Payments\Core\X402\X402AmountConverter;
use Swag\X402Payments\Core\X402\X402PayloadValidator;
use Swag\X402Payments\Tests\Unit\Support\X402Fixtures;

/**
 * Local binding checks (spec 12.3) and the mandatory tampering cases from
 * spec 24.4: altered amount, altered payTo, altered network, expired quote,
 * expired authorization window. All of these must fail BEFORE any
 * facilitator call - the validator is that gate.
 */
// One test method per rejection case; splitting would hide the suite's shape.
// @mago-expect lint:too-many-methods
#[CoversClass(X402PayloadValidator::class)]
final class X402PayloadValidatorTest extends TestCase
{
    private X402PayloadValidator $validator;

    #[\Override]
    protected function setUp(): void
    {
        $this->validator = new X402PayloadValidator(new X402AmountConverter(), X402Fixtures::clock());
    }

    public function testMatchingPayloadPasses(): void
    {
        $this->validator->validate(X402Fixtures::payload(), X402Fixtures::session());

        $this->addToAssertionCount(1);
    }

    public function testPayloadPayingMoreThanRequiredPasses(): void
    {
        $payload = X402Fixtures::payload(['value' => '42990001']);

        $this->validator->validate($payload, X402Fixtures::session());

        $this->addToAssertionCount(1);
    }

    public function testMerchantWalletComparisonIsCaseInsensitive(): void
    {
        $payload = X402Fixtures::payload(['to' => strtoupper(X402Fixtures::MERCHANT_WALLET)]);

        $this->validator->validate($payload, X402Fixtures::session());

        $this->addToAssertionCount(1);
    }

    public function testExpiredQuoteIsRejected(): void
    {
        $session = X402Fixtures::session(['expiresAt' => X402Fixtures::now()->modify('-1 second')]);

        try {
            $this->validator->validate(X402Fixtures::payload(), $session);
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::QUOTE_EXPIRED, $exception->getErrorCode());
        }
    }

    public function testExpiryIsCheckedBeforePayloadBinding(): void
    {
        $session = X402Fixtures::session(['expiresAt' => X402Fixtures::now()->modify('-1 second')]);
        $tamperedEverything = X402Fixtures::payload(['to' => '0xattacker', 'value' => '1', 'network' => 'evil']);

        try {
            $this->validator->validate($tamperedEverything, $session);
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::QUOTE_EXPIRED, $exception->getErrorCode());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpayableStateCases(): iterable
    {
        yield 'settled sessions cannot be paid again' => [X402PaymentSessionStates::STATE_SETTLED];
        yield 'expired sessions cannot be paid' => [X402PaymentSessionStates::STATE_EXPIRED];
    }

    #[DataProvider('unpayableStateCases')]
    public function testTerminalSessionStatesAreNotPayable(string $state): void
    {
        $session = X402Fixtures::session(['state' => $state]);

        try {
            $this->validator->validate(X402Fixtures::payload(), $session);
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::TRANSACTION_NOT_PAYABLE, $exception->getErrorCode());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function retryableStateCases(): iterable
    {
        yield 'verify_failed is retryable' => [X402PaymentSessionStates::STATE_VERIFY_FAILED];
        yield 'settlement_failed is retryable' => [X402PaymentSessionStates::STATE_SETTLEMENT_FAILED];
        yield 'payload_received is retryable' => [X402PaymentSessionStates::STATE_PAYLOAD_RECEIVED];
    }

    #[DataProvider('retryableStateCases')]
    public function testTransientFailureStatesStayPayable(string $state): void
    {
        $this->validator->validate(X402Fixtures::payload(), X402Fixtures::session(['state' => $state]));

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function tamperedPayloadCases(): iterable
    {
        yield 'unsupported x402 version' => [['x402Version' => 2], 'x402Version'];
        yield 'altered scheme' => [['scheme' => 'upto'], 'scheme'];
        yield 'altered network' => [['network' => 'base'], 'network'];
        yield 'altered payTo (attacker wallet)' => [['to' => X402Fixtures::PAYER_WALLET], 'authorization.to'];
        yield 'altered amount (pays less)' => [['value' => '4299000'], 'authorization.value'];
        yield 'authorization already expired' => [
            ['validBefore' => X402Fixtures::now()->getTimestamp()],
            'validBefore',
        ];
        yield 'authorization not yet valid' => [
            ['validAfter' => X402Fixtures::now()->modify('+1 hour')->getTimestamp()],
            'validAfter',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('tamperedPayloadCases')]
    public function testTamperedPayloadIsRejectedBeforeFacilitatorCall(array $overrides, string $field): void
    {
        try {
            $this->validator->validate(X402Fixtures::payload($overrides), X402Fixtures::session());
            self::fail(\sprintf('tampering with %s must be rejected', $field));
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::PAYLOAD_MISMATCH, $exception->getErrorCode());
        }
    }
}

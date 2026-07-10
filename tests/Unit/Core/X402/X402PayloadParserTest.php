<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Core\X402;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\X402Payments\Core\X402\Exception\X402Exception;
use Swag\X402Payments\Core\X402\X402PayloadParser;
use Swag\X402Payments\Tests\Unit\Support\X402Fixtures;

// One test method per rejection case; splitting would hide the suite's shape.
// @mago-expect lint:too-many-methods
#[CoversClass(X402PayloadParser::class)]
final class X402PayloadParserTest extends TestCase
{
    private X402PayloadParser $parser;

    #[\Override]
    protected function setUp(): void
    {
        $this->parser = new X402PayloadParser();
    }

    public function testParsesValidHeaderIntoNormalizedDto(): void
    {
        $data = X402Fixtures::paymentHeaderData();
        $header = X402Fixtures::encodePaymentHeader($data);

        $payload = $this->parser->parse($header);

        self::assertSame(1, $payload->x402Version);
        self::assertSame('exact', $payload->scheme);
        self::assertSame(X402Fixtures::NETWORK, $payload->network);
        self::assertSame(X402Fixtures::PAYER_WALLET, $payload->from);
        self::assertSame(X402Fixtures::MERCHANT_WALLET, $payload->to);
        self::assertSame(X402Fixtures::ATOMIC_AMOUNT, $payload->value);
        self::assertSame(0, $payload->validAfter);
        self::assertSame($data['payload']['authorization']['validBefore'], $payload->validBefore);
        self::assertSame($data['payload']['authorization']['nonce'], $payload->nonce);
        self::assertSame($data, $payload->raw);
    }

    public function testPayloadHashIsBoundToTheRawHeader(): void
    {
        $header = X402Fixtures::encodePaymentHeader(X402Fixtures::paymentHeaderData());

        $payload = $this->parser->parse($header);

        self::assertSame(hash('sha256', $header), $payload->payloadHash);
    }

    public function testDifferentHeadersProduceDifferentPayloadHashes(): void
    {
        $first = $this->parser->parse(X402Fixtures::encodePaymentHeader(X402Fixtures::paymentHeaderData()));
        $second = $this->parser->parse(X402Fixtures::encodePaymentHeader(X402Fixtures::paymentHeaderData([
            'nonce' => '0x' . str_repeat('ee', 32),
        ])));

        self::assertNotSame($first->payloadHash, $second->payloadHash);
    }

    public function testMissingEnvelopeFieldsAreLeftForTheValidatorToReject(): void
    {
        $data = X402Fixtures::paymentHeaderData();
        unset($data['x402Version'], $data['scheme'], $data['network']);

        $payload = $this->parser->parse(X402Fixtures::encodePaymentHeader($data));

        self::assertSame(0, $payload->x402Version);
        self::assertSame('', $payload->scheme);
        self::assertSame('', $payload->network);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaderCases(): iterable
    {
        yield 'not base64' => ['%%%not-base64%%%'];
        yield 'base64 of invalid JSON' => [base64_encode('{not json')];
        yield 'JSON scalar instead of object' => [base64_encode('"42"')];
        yield 'missing payload object' => [base64_encode('{"x402Version":1}')];
    }

    #[DataProvider('malformedHeaderCases')]
    public function testMalformedHeaderIsRejectedWithStructuredError(string $header): void
    {
        try {
            $this->parser->parse($header);
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::INVALID_PAYMENT_HEADER, $exception->getErrorCode());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingFieldCases(): iterable
    {
        yield 'signature' => ['signature'];
        yield 'from' => ['from'];
        yield 'to' => ['to'];
        yield 'value' => ['value'];
        yield 'nonce' => ['nonce'];
    }

    #[DataProvider('missingFieldCases')]
    public function testMissingRequiredFieldIsRejected(string $field): void
    {
        $data = X402Fixtures::paymentHeaderData();
        if ($field === 'signature') {
            unset($data['payload']['signature']);
        } else {
            unset($data['payload']['authorization'][$field]);
        }

        $this->expectException(X402Exception::class);

        $this->parser->parse(X402Fixtures::encodePaymentHeader($data));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonAtomicValueCases(): iterable
    {
        yield 'decimal string' => ['42.99'];
        yield 'hex string' => ['0x2A'];
        yield 'negative' => ['-42990000'];
        yield 'empty' => [''];
        yield 'integer instead of string' => [42990000];
    }

    #[DataProvider('nonAtomicValueCases')]
    public function testNonAtomicAuthorizationValueIsRejected(mixed $value): void
    {
        $data = X402Fixtures::paymentHeaderData(['value' => $value]);

        $this->expectException(X402Exception::class);

        $this->parser->parse(X402Fixtures::encodePaymentHeader($data));
    }
}

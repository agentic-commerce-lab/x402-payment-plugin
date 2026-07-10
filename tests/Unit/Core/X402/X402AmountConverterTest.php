<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Core\X402;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\X402Payments\Core\X402\Exception\X402Exception;
use Swag\X402Payments\Core\X402\X402AmountConverter;

#[CoversClass(X402AmountConverter::class)]
final class X402AmountConverterTest extends TestCase
{
    private X402AmountConverter $converter;

    #[\Override]
    protected function setUp(): void
    {
        $this->converter = new X402AmountConverter();
    }

    /**
     * @return iterable<string, array{float, int, string}>
     */
    public static function atomicConversionCases(): iterable
    {
        yield 'spec example: 42.99 with 6 decimals' => [42.99, 6, '42990000'];
        yield 'sub-unit amount' => [0.5, 6, '500000'];
        yield 'zero amount' => [0.0, 6, '0'];
        yield 'zero decimals' => [42.0, 0, '42'];
        yield 'two decimals' => [1000.00, 2, '100000'];
        yield 'rounds excess precision' => [42.999, 2, '4300'];
        yield 'float drift does not leak into atomic units' => [0.1 + 0.2, 6, '300000'];
        yield '18-decimal token' => [1.5, 18, '1500000000000000000'];
    }

    #[DataProvider('atomicConversionCases')]
    public function testToAtomic(float $amount, int $decimals, string $expected): void
    {
        self::assertSame($expected, $this->converter->toAtomic($amount, $decimals));
    }

    public function testNegativeAmountIsRejected(): void
    {
        $this->expectException(X402Exception::class);
        $this->expectExceptionMessage('outside the configured x402 payment limits');

        $this->converter->toAtomic(-0.01, 6);
    }

    public function testNegativeDecimalsAreRejected(): void
    {
        $this->expectException(X402Exception::class);

        $this->converter->toAtomic(1.0, -1);
    }

    public function testAbsurdDecimalsAreRejected(): void
    {
        $this->expectException(X402Exception::class);

        $this->converter->toAtomic(1.0, 37);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function isAtLeastCases(): iterable
    {
        yield 'equal' => ['42990000', '42990000', true];
        yield 'one above' => ['42990001', '42990000', true];
        yield 'one below' => ['42989999', '42990000', false];
        yield 'longer value wins' => ['142990000', '42990000', true];
        yield 'shorter value loses' => ['9', '10', false];
        yield 'leading zeros are ignored' => ['042990000', '42990000', true];
        yield 'zero vs zero' => ['0', '0', true];
        yield 'zero below requirement' => ['0', '1', false];
    }

    #[DataProvider('isAtLeastCases')]
    public function testIsAtLeast(string $value, string $required, bool $expected): void
    {
        self::assertSame($expected, $this->converter->isAtLeast($value, $required));
    }
}

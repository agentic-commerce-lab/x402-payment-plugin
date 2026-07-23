<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Ucp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\X402Payments\Ucp\UcpBaseUriResolver;

#[CoversClass(UcpBaseUriResolver::class)]
final class UcpBaseUriResolverTest extends TestCase
{
    public function testPrefersConfiguredBaseUriWhenPresent(): void
    {
        self::assertSame('https://shop.example.com', (new UcpBaseUriResolver())->resolve(
            'https://shop.example.com/',
            '127.0.0.1:8000',
        ));
    }

    public function testFallsBackToHostHeaderWithPortForLocalDev(): void
    {
        self::assertSame('http://127.0.0.1:8000', (new UcpBaseUriResolver())->resolve('', '127.0.0.1:8000'));
    }

    public function testTreatsNullConfiguredBaseUriAsAbsent(): void
    {
        self::assertSame('http://localhost:8000', (new UcpBaseUriResolver())->resolve(null, 'localhost:8000'));
    }

    public function testFallsBackToHttpsForPublicHost(): void
    {
        self::assertSame('https://shop.example.com', (new UcpBaseUriResolver())->resolve(null, 'shop.example.com'));
    }

    public function testReturnsEmptyWhenNothingResolvable(): void
    {
        self::assertSame('', (new UcpBaseUriResolver())->resolve(null, ''));
    }
}

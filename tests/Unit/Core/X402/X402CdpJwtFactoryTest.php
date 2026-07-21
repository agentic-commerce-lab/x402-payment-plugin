<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Core\X402;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\X402Payments\Core\X402\X402CdpJwtFactory;

#[CoversClass(X402CdpJwtFactory::class)]
final class X402CdpJwtFactoryTest extends TestCase
{
    public function testMintsVerifiableEd25519JwtWithCdpClaims(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);

        $jwt = (new X402CdpJwtFactory())->create(
            'organizations/o/apiKeys/k',
            base64_encode($secret),
            'POST',
            'https://api.cdp.coinbase.com/platform/v2/x402/settle',
        );

        [$h, $p, $s] = explode('.', $jwt);
        $header = json_decode($this->b64urlDecode($h), true, 512, \JSON_THROW_ON_ERROR);
        $payload = json_decode($this->b64urlDecode($p), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('EdDSA', $header['alg']);
        self::assertSame('JWT', $header['typ']);
        self::assertSame('organizations/o/apiKeys/k', $header['kid']);
        self::assertNotEmpty($header['nonce']);

        self::assertSame('organizations/o/apiKeys/k', $payload['sub']);
        self::assertSame('cdp', $payload['iss']);
        self::assertSame('POST api.cdp.coinbase.com/platform/v2/x402/settle', $payload['uri']);
        self::assertSame($payload['nbf'] + 120, $payload['exp']);

        self::assertTrue(
            sodium_crypto_sign_verify_detached($this->b64urlDecode($s), $h . '.' . $p, $public),
            'JWT signature must verify against the Ed25519 public key over header.payload',
        );
    }

    public function testAcceptsA32ByteSeedSecret(): void
    {
        $seed = random_bytes(\SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));

        $jwt = (new X402CdpJwtFactory())->create('k', base64_encode($seed), 'POST', 'https://h.example/verify');
        [$h, $p, $s] = explode('.', $jwt);

        self::assertTrue(sodium_crypto_sign_verify_detached($this->b64urlDecode($s), $h . '.' . $p, $public));
    }

    private function b64urlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - (\strlen($data) % 4)) % 4), true);
    }
}

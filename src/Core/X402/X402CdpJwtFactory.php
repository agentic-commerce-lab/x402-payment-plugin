<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402;

use Swag\X402Payments\Core\X402\Exception\X402Exception;

/**
 * Mints a short-lived Coinbase CDP Bearer JWT for a single facilitator request.
 *
 * CDP does not accept a static token; each request needs a JWT signed with the
 * CDP Ed25519 API-key secret, carrying a `uri` claim bound to the exact request
 * method + host + path. See CDP "JWT authentication". Ed25519 (EdDSA) only —
 * CDP's recommended key type — signed with libsodium (no external JWT lib).
 */
final class X402CdpJwtFactory
{
    private const TTL_SECONDS = 120;

    /**
     * @param string $keyId  CDP API key name/id (JWT `kid` + `sub`)
     * @param string $secret CDP Ed25519 secret, base64 (32-byte seed or 64-byte secret key)
     * @param string $method HTTP method of the facilitator request (e.g. POST)
     * @param string $url    absolute facilitator URL (e.g. https://api.cdp.coinbase.com/platform/v2/x402/settle)
     */
    public function create(string $keyId, string $secret, string $method, string $url): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        if ('' === $host) {
            throw X402Exception::facilitatorUnavailable();
        }

        $now = time();
        $header = [
            'typ' => 'JWT',
            'alg' => 'EdDSA',
            'kid' => $keyId,
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $payload = [
            'sub' => $keyId,
            'iss' => 'cdp',
            'nbf' => $now,
            'exp' => $now + self::TTL_SECONDS,
            // CDP binds the token to the request: "<METHOD> <host><path>", no scheme.
            'uri' => strtoupper($method) . ' ' . $host . $path,
        ];

        $signingInput = $this->b64url($this->json($header)) . '.' . $this->b64url($this->json($payload));
        $signature = sodium_crypto_sign_detached($signingInput, $this->secretKey($secret));

        return $signingInput . '.' . $this->b64url($signature);
    }

    private function secretKey(string $secret): string
    {
        $raw = base64_decode(strtr(trim($secret), '-_', '+/'), true);
        if (false === $raw) {
            throw X402Exception::facilitatorUnavailable();
        }

        // 64-byte Ed25519 secret key: use directly. 32-byte seed: derive the keypair.
        if (\SODIUM_CRYPTO_SIGN_SEEDBYTES === \strlen($raw)) {
            $raw = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($raw));
        }

        if (\SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== \strlen($raw)) {
            throw X402Exception::facilitatorUnavailable();
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

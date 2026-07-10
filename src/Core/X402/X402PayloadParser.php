<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402;

use Swag\X402Payments\Core\X402\Dto\PaymentPayload;
use Swag\X402Payments\Core\X402\Exception\X402Exception;

// Strict input validation is inherently branchy; each branch is one reject.
// @mago-expect lint:cyclomatic-complexity
class X402PayloadParser
{
    /**
     * Decodes a base64 encoded x402 `exact` scheme payment payload from the
     * X-PAYMENT header into a normalized DTO (spec section 12.2).
     */
    public function parse(string $header): PaymentPayload
    {
        $decoded = $this->decode($header);
        $payload = $this->requireArray($decoded, 'payload');
        $authorization = $this->requireArray($payload, 'authorization');

        $signature = $this->requireString($payload, 'signature');
        $from = $this->requireString($authorization, 'from');
        $to = $this->requireString($authorization, 'to');
        $value = $this->requireString($authorization, 'value');
        $nonce = $this->requireString($authorization, 'nonce');

        if (!ctype_digit($value)) {
            throw X402Exception::invalidPaymentHeader('authorization.value must be an atomic integer string');
        }

        return new PaymentPayload(
            x402Version: (int) ($decoded['x402Version'] ?? 0),
            scheme: (string) ($decoded['scheme'] ?? ''),
            network: (string) ($decoded['network'] ?? ''),
            signature: $signature,
            from: $from,
            to: $to,
            value: $value,
            validAfter: (int) ($authorization['validAfter'] ?? 0),
            validBefore: (int) ($authorization['validBefore'] ?? 0),
            nonce: $nonce,
            payloadHash: hash('sha256', $header),
            raw: $decoded,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $header): array
    {
        $binary = base64_decode($header, true);
        if ($binary === false) {
            throw X402Exception::invalidPaymentHeader('not valid base64');
        }

        try {
            $decoded = json_decode($binary, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw X402Exception::invalidPaymentHeader('not valid JSON: ' . $exception->getMessage());
        }

        if (!\is_array($decoded)) {
            throw X402Exception::invalidPaymentHeader('payload must be a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function requireArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            throw X402Exception::invalidPaymentHeader(\sprintf('missing object field "%s"', $key));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw X402Exception::invalidPaymentHeader(\sprintf('missing string field "%s"', $key));
        }

        return $value;
    }
}

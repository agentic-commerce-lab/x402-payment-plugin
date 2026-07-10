<?php

declare(strict_types=1);

namespace Swag\X402Payments\StoreApi;

use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * Normalized view on an incoming x402 pay request. Raw credentials are kept
 * only as hashes wherever they are persisted later (spec section 9.1).
 */
final readonly class X402PayRequest
{
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        public ?string $deepLinkCode,
        public ?string $contextTokenHash,
        public ?string $deepLinkCodeHash,
        public string $idempotencyKeyHash,
        public ?string $paymentHeader,
        public string $resourceUrl,
    ) {}

    public static function fromHttpRequest(Request $request): self
    {
        $deepLinkCode = self::nonEmpty(self::extractDeepLinkCode($request));
        $contextToken = self::nonEmpty($request->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
        $idempotencyKey = (string) $request->headers->get('Idempotency-Key', '');

        return new self(
            deepLinkCode: $deepLinkCode,
            contextTokenHash: self::hashOrNull($contextToken),
            deepLinkCodeHash: self::hashOrNull($deepLinkCode),
            idempotencyKeyHash: hash('sha256', $idempotencyKey),
            paymentHeader: self::nonEmpty($request->headers->get('X-PAYMENT')),
            resourceUrl: $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo(),
        );
    }

    private static function extractDeepLinkCode(Request $request): ?string
    {
        $fromQuery = $request->query->getString('deepLinkCode');
        if ($fromQuery !== '') {
            return $fromQuery;
        }

        $decoded = json_decode($request->getContent(), associative: true);
        $fromBody = \is_array($decoded) ? $decoded['deepLinkCode'] ?? null : null;

        return \is_string($fromBody) ? $fromBody : null;
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value !== null && $value !== '' ? $value : null;
    }

    private static function hashOrNull(?string $value): ?string
    {
        return $value !== null ? hash('sha256', $value) : null;
    }
}

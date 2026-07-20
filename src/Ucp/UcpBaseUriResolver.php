<?php

declare(strict_types=1);

namespace Swag\X402Payments\Ucp;

/**
 * Resolves the public base URL used to build the x402 pay-route URL.
 *
 * On this deployment the UCP SDK's static runtime configuration ships an empty
 * baseUri, and RequestContext::$host is host-only (no port). The HTTP Host
 * header carries host+port, so we fall back to it for local/dev setups. Kept
 * free of SDK/framework types so it stays a pure, unit-testable helper.
 */
final class UcpBaseUriResolver
{
    public function resolve(?string $configuredBaseUri, string $hostHeader): string
    {
        $configured = rtrim($configuredBaseUri ?? '', '/');
        if ($configured !== '') {
            return $configured;
        }

        if ($hostHeader === '') {
            return '';
        }

        $isLocal = str_starts_with($hostHeader, 'localhost') || str_starts_with($hostHeader, '127.0.0.1');

        return ($isLocal ? 'http' : 'https') . '://' . $hostHeader;
    }
}

<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402;

use Swag\X402Payments\Core\X402\Exception\X402Exception;

class X402AmountConverter
{
    /**
     * Converts a Shopware decimal amount into token atomic units without
     * floating point drift, e.g. 42.99 EUR with 6 decimals -> "42990000".
     */
    public function toAtomic(float $amount, int $decimals): string
    {
        if ($amount < 0.0 || $decimals < 0 || $decimals > 36) {
            throw X402Exception::amountOutOfBounds();
        }

        $formatted = number_format($amount, $decimals, '.', '');
        $parts = explode('.', $formatted, 2);
        $integerPart = $parts[0];
        $fractionPart = $parts[1] ?? '';
        $atomic = ltrim($integerPart . str_pad($fractionPart, $decimals, '0'), '0');

        return $atomic === '' ? '0' : $atomic;
    }

    public function isAtLeast(string $atomicValue, string $atomicRequired): bool
    {
        $value = ltrim($atomicValue, '0');
        $required = ltrim($atomicRequired, '0');

        if (\strlen($value) !== \strlen($required)) {
            return \strlen($value) > \strlen($required);
        }

        return strcmp($value, $required) >= 0;
    }
}

<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402;

use Swag\X402Payments\Core\X402\Dto\PaymentRequirements;

class X402QuoteHasher
{
    /**
     * Binds the quote to the exact Shopware order transaction and payment
     * requirements (spec section 11).
     */
    public function hash(
        string $salesChannelId,
        string $orderTransactionId,
        PaymentRequirements $requirements,
        string $expiresAtIso,
    ): string {
        $material = implode('|', [
            $salesChannelId,
            $orderTransactionId,
            $requirements->scheme,
            $requirements->network,
            $requirements->maxAmountRequired,
            $requirements->asset,
            $requirements->payTo,
            $requirements->resource,
            $expiresAtIso,
        ]);

        return hash('sha256', $material);
    }

    public function hashOpaque(string $value): string
    {
        return hash('sha256', $value);
    }
}

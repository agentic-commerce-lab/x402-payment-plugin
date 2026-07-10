<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Checkout\Payment;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Intentionally minimal (spec section 6.2): the canonical x402 payment flow
 * runs through the custom Store API routes, not through a redirect page. The
 * handler exists so Shopware recognizes x402 as a native payment method and
 * keeps the order transaction open until settlement.
 */
class X402PaymentHandler extends AbstractPaymentHandler
{
    #[\Override]
    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false;
    }

    #[\Override]
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct,
    ): ?RedirectResponse {
        // Headless mode: the transaction stays open; settlement happens via
        // /store-api/x402/order/{orderId}/pay (spec sections 7.2 and 12.5).
        return null;
    }
}

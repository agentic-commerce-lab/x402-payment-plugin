<?php

declare(strict_types=1);

namespace Swag\X402Payments\Ucp;

use Shopware\Core\Framework\Context;
use Swag\X402Payments\Core\Checkout\Payment\X402PaymentMethodResolver;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * UCP payment handler descriptor for x402 (spec section 26.4, Tier 3).
 *
 * x402 is a DELEGATED payment scheme: per-order signed EIP-3009
 * authorizations, no credential vaulting. supportsTokenization() is
 * therefore false by contract - never fake tokenization. The handler is only
 * advertised in /.well-known/ucp when the SwagAgenticCommerce sales channel
 * opts in via `advertiseDelegatedPaymentHandlers`.
 *
 * Registered conditionally: only when ucp-php-sdk is installed (see
 * SwagX402Payments::build()).
 */
class X402UcpPaymentHandler implements PaymentHandlerInterface
{
    public const HANDLER_ID = 'com.shopware.x402';

    public const INSTRUMENT_TYPE = 'x402';

    public function __construct(
        private readonly X402PaymentMethodResolver $paymentMethodResolver,
    ) {}

    public function id(): string
    {
        return self::HANDLER_ID;
    }

    public function describe(RequestContext $context): PaymentHandlerDescriptor
    {
        return new PaymentHandlerDescriptor(
            $this->id(),
            $this->id(),
            '2026-07-13',
            'https://developer.shopware.com/ucp/payment-handlers/x402',
            'https://ucp.dev/schemas/payments/delegate-payment.json',
            [],
            [
                'tokenization' => false,
                'scheme' => 'exact',
                'instrument_type' => self::INSTRUMENT_TYPE,
                'description' =>
                    'x402 protocol payments (HTTP 402, signed EIP-3009 stablecoin authorizations). '
                        . 'Settlement runs through the shop\'s x402 pay route; see the extra.x402 object on completed checkouts.',
            ],
        );
    }

    /**
     * @return array{paymentMethodId: string, token: string}
     */
    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
    {
        return [
            'paymentMethodId' => $this->paymentMethodResolver->getX402PaymentMethodId(Context::createDefaultContext()),
            'token' => hash('sha256', (string) json_encode($instrument->credential)),
        ];
    }

    public function supportsTokenization(): bool
    {
        return false;
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
    {
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Swag\X402Payments\Ucp;

use Psr\Clock\ClockInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizationResult;
use Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizerInterface;
use Swag\X402Payments\Core\X402\Config\X402Config;
use Swag\X402Payments\Core\X402\Config\X402ConfigService;
use Swag\X402Payments\Core\X402\X402AmountConverter;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * Pre-order gate for UCP checkout completions carrying an x402 payment
 * instrument (AP2-style: the instrument credential is a signed EIP-3009
 * authorization for the cart total).
 *
 * This authorizer validates the credential's binding to this shop and cart
 * BEFORE the order is placed - it does NOT settle. Funds move only through
 * the x402 pay route (verify + settle via facilitator), and the order
 * transaction stays open until settlement succeeds, preserving the plugin's
 * "never paid before settlement" invariant.
 */
// Strict credential validation is inherently branchy; each branch is one reject.
// @mago-expect lint:cyclomatic-complexity
class X402UcpPaymentAuthorizer implements PaymentAuthorizerInterface
{
    public function __construct(
        private readonly X402ConfigService $configService,
        private readonly X402AmountConverter $amountConverter,
        private readonly ClockInterface $clock,
    ) {}

    public function supports(string $handlerId): bool
    {
        return $handlerId === X402UcpPaymentHandler::HANDLER_ID;
    }

    public function authorize(
        CheckoutCompleteRequest $request,
        PaymentInstrument $instrument,
        Cart $cart,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): PaymentAuthorizationResult {
        $config = $this->configService->getConfig($context->getSalesChannelId());
        if (!$config->isComplete()) {
            return PaymentAuthorizationResult::failed(
                'x402_not_configured',
                'x402 payments are not configured for this sales channel.',
            );
        }

        $failure = $this->validateCredential($instrument->credential, $config, $cart);
        if ($failure !== null) {
            return PaymentAuthorizationResult::failed('x402_invalid_credential', $failure);
        }

        return PaymentAuthorizationResult::authorized(hash('sha256', (string) json_encode($instrument->credential)));
    }

    /**
     * Mirrors the local binding checks of the pay route (spec 12.3) against
     * the cart, returning the first violation or null when valid.
     *
     * @param array<string, mixed> $credential
     */
    private function validateCredential(array $credential, X402Config $config, Cart $cart): ?string
    {
        // UCP payment_credential schema: "type" is the one required member.
        $credentialType = $credential['type'] ?? null;
        if (!\is_string($credentialType) || $credentialType === '') {
            return 'credential must declare a "type" (UCP payment_credential schema).';
        }

        $payload = \is_array($credential['payload'] ?? null) ? $credential['payload'] : $credential;
        $authorization = \is_array($payload['authorization'] ?? null) ? $payload['authorization'] : null;
        $signature = $payload['signature'] ?? null;

        if ($authorization === null || !\is_string($signature) || $signature === '') {
            return 'credential must contain payload.signature and payload.authorization (EIP-3009).';
        }

        if (($credential['network'] ?? $payload['network'] ?? '') !== $config->network) {
            return \sprintf('credential network does not match the shop network "%s".', $config->network);
        }

        $to = $authorization['to'] ?? null;
        if (!\is_string($to) || !hash_equals(strtolower($config->merchantWalletAddress), strtolower($to))) {
            return 'authorization.to does not match the merchant wallet.';
        }

        $value = $authorization['value'] ?? null;
        $requiredAtomic = $this->amountConverter->toAtomic($cart->getPrice()->getTotalPrice(), $config->assetDecimals);
        if (
            !\is_string($value)
            || !ctype_digit($value)
            || !$this->amountConverter->isAtLeast($value, $requiredAtomic)
        ) {
            return \sprintf('authorization.value must cover the cart total (%s atomic units).', $requiredAtomic);
        }

        $validBefore = (int) ($authorization['validBefore'] ?? 0);
        if ($validBefore <= $this->clock->now()->getTimestamp()) {
            return 'authorization.validBefore is in the past.';
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Checkout\Order;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Config\X402Config;
use Swag\X402Payments\Core\X402\Exception\X402Exception;

/**
 * Verifies order ownership per spec section 7.2.0: either the caller's
 * context belongs to the customer that placed the order (proof A), or the
 * caller presents the order's deepLinkCode (proof B). Proof B is what makes
 * UCP-placed guest orders payable (spec section 26.3).
 */
class X402OrderOwnershipVerifier
{
    /**
     * @return string the ownership proof kind that succeeded
     */
    public function verify(
        OrderEntity $order,
        SalesChannelContext $salesChannelContext,
        ?string $deepLinkCode,
        X402Config $config,
    ): string {
        if ($this->matchesCustomer($order, $salesChannelContext)) {
            return X402PaymentSessionStates::OWNERSHIP_PROOF_CONTEXT_TOKEN;
        }

        if ($this->matchesDeepLinkCode($order, $deepLinkCode, $config)) {
            return X402PaymentSessionStates::OWNERSHIP_PROOF_DEEP_LINK_CODE;
        }

        throw X402Exception::ownershipProofMissing();
    }

    private function matchesCustomer(OrderEntity $order, SalesChannelContext $salesChannelContext): bool
    {
        $contextCustomerId = $salesChannelContext->getCustomerId();
        $orderCustomerId = $order->getOrderCustomer()?->getCustomerId();

        return $contextCustomerId !== null
        && $orderCustomerId !== null
        && hash_equals($orderCustomerId, $contextCustomerId);
    }

    private function matchesDeepLinkCode(OrderEntity $order, ?string $deepLinkCode, X402Config $config): bool
    {
        if (!$config->allowDeepLinkOwnershipProof) {
            return false;
        }

        $orderDeepLinkCode = $order->getDeepLinkCode();

        return $deepLinkCode !== null
        && $deepLinkCode !== ''
        && $orderDeepLinkCode !== null
        && hash_equals($orderDeepLinkCode, $deepLinkCode);
    }
}

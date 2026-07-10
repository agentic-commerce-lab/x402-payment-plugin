<?php

declare(strict_types=1);

namespace Swag\X402Payments\StoreApi;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\X402Payments\Core\Checkout\Order\X402OrderOwnershipVerifier;
use Swag\X402Payments\Core\Checkout\Order\X402OrderResolver;
use Swag\X402Payments\Core\Checkout\Payment\X402PaymentMethodResolver;
use Swag\X402Payments\Core\Checkout\Payment\X402PaymentMethodSwitcher;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Config\X402Config;
use Swag\X402Payments\Core\X402\Dto\X402SessionContext;
use Swag\X402Payments\Core\X402\Exception\X402Exception;
use Swag\X402Payments\Core\X402\X402PaymentSessionService;

/**
 * Prepares the payable x402 session for an order: resolves the order, checks
 * ownership (spec 7.2.0), applies the after-order payment method switch when
 * needed (spec 26.3), and creates or reuses the payment session.
 */
class X402PaymentSessionFactory
{
    private const PAYABLE_TRANSACTION_STATES = [
        OrderTransactionStates::STATE_OPEN,
        OrderTransactionStates::STATE_IN_PROGRESS,
    ];

    public function __construct(
        private readonly X402OrderResolver $orderResolver,
        private readonly X402OrderOwnershipVerifier $ownershipVerifier,
        private readonly X402PaymentMethodResolver $paymentMethodResolver,
        private readonly X402PaymentMethodSwitcher $paymentMethodSwitcher,
        private readonly X402PaymentSessionService $sessionService,
    ) {}

    public function prepare(
        string $orderId,
        X402PayRequest $payRequest,
        X402Config $config,
        SalesChannelContext $salesChannelContext,
    ): X402PaymentSessionEntity {
        $context = $salesChannelContext->getContext();
        $order = $this->orderResolver->resolve($orderId, $salesChannelContext->getSalesChannelId(), $context);

        $proof = $this->ownershipVerifier->verify($order, $salesChannelContext, $payRequest->deepLinkCode, $config);

        $transaction = $this->paymentMethodSwitcher->ensureX402Transaction(
            $order,
            $this->orderResolver->latestTransaction($order),
            $this->paymentMethodResolver->getX402PaymentMethodId($context),
            $config->allowAfterOrderPaymentMethodSwitch,
            $context,
        );

        $transactionState = $transaction->getStateMachineState()?->getTechnicalName() ?? '';
        if (!\in_array($transactionState, self::PAYABLE_TRANSACTION_STATES, true)) {
            return $this->settledSessionOrFail($transaction->getId(), $transactionState, $context);
        }

        return $this->sessionService->getOrCreate(
            new X402SessionContext(
                order: $order,
                transaction: $transaction,
                config: $config,
                salesChannelId: $salesChannelContext->getSalesChannelId(),
                resourceUrl: $payRequest->resourceUrl,
                ownershipProof: $proof,
                contextTokenHash: match ($proof) {
                    X402PaymentSessionStates::OWNERSHIP_PROOF_CONTEXT_TOKEN => $payRequest->contextTokenHash,
                    default => null,
                },
                deepLinkCodeHash: match ($proof) {
                    X402PaymentSessionStates::OWNERSHIP_PROOF_DEEP_LINK_CODE => $payRequest->deepLinkCodeHash,
                    default => null,
                },
                idempotencyKeyHash: $payRequest->idempotencyKeyHash,
            ),
            $context,
        );
    }

    /**
     * A paid transaction with a settled session is an idempotent success
     * (spec section 14: "Already paid order" returns the paid status).
     */
    private function settledSessionOrFail(
        string $transactionId,
        string $transactionState,
        \Shopware\Core\Framework\Context $context,
    ): X402PaymentSessionEntity {
        if ($transactionState === OrderTransactionStates::STATE_PAID) {
            $session = $this->sessionService->findByOrderTransactionId($transactionId, $context);
            if ($session !== null && $session->isSettled()) {
                return $session;
            }
        }

        throw X402Exception::transactionNotPayable($transactionState);
    }
}

<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Checkout\Payment;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\StateMachineException;

/**
 * Single owner of Shopware order transaction state transitions for x402
 * payments (spec section 12.6). Routes and services must not call the state
 * machine directly.
 */
class X402TransactionStateService
{
    public function __construct(
        private readonly OrderTransactionStateHandler $stateHandler,
        private readonly LoggerInterface $logger,
    ) {}

    public function markInProgress(string $transactionId, Context $context): void
    {
        try {
            $this->stateHandler->process($transactionId, $context);
        } catch (StateMachineException) {
            // Already in progress from an earlier retry - benign.
            return;
        }

        $this->log($transactionId, OrderTransactionStates::STATE_IN_PROGRESS);
    }

    public function markPaid(string $transactionId, Context $context): void
    {
        $this->stateHandler->paid($transactionId, $context);
        $this->log($transactionId, OrderTransactionStates::STATE_PAID);
    }

    public function markFailed(string $transactionId, Context $context): void
    {
        $this->stateHandler->fail($transactionId, $context);
        $this->log($transactionId, OrderTransactionStates::STATE_FAILED);
    }

    public function markCancelled(string $transactionId, Context $context): void
    {
        $this->stateHandler->cancel($transactionId, $context);
        $this->log($transactionId, OrderTransactionStates::STATE_CANCELLED);
    }

    private function log(string $transactionId, string $targetState): void
    {
        $this->logger->info('x402 order transaction state transition.', [
            'orderTransactionId' => $transactionId,
            'targetState' => $targetState,
        ]);
    }
}

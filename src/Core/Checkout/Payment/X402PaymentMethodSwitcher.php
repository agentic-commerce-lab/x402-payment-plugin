<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Checkout\Payment;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
use Swag\X402Payments\Core\X402\Exception\X402Exception;

/**
 * After-order payment method switch (spec section 26.3): cancels an open
 * transaction that uses another payment method and creates a fresh open
 * transaction bound to the x402 payment method. Required for orders placed
 * through UCP checkout, which always uses the sales channel default method.
 */
class X402PaymentMethodSwitcher
{
    /**
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        private readonly EntityRepository $orderRepository,
        private readonly InitialStateIdLoader $initialStateIdLoader,
        private readonly X402TransactionStateService $transactionStateService,
    ) {}

    public function ensureX402Transaction(
        OrderEntity $order,
        OrderTransactionEntity $transaction,
        string $x402PaymentMethodId,
        bool $allowSwitch,
        Context $context,
    ): OrderTransactionEntity {
        if ($transaction->getPaymentMethodId() === $x402PaymentMethodId) {
            return $transaction;
        }

        if (!$allowSwitch) {
            throw X402Exception::paymentMethodNotSelected();
        }

        $state = $transaction->getStateMachineState()?->getTechnicalName() ?? '';
        if ($state !== OrderTransactionStates::STATE_OPEN) {
            throw X402Exception::transactionNotPayable($state);
        }

        $this->transactionStateService->markCancelled($transaction->getId(), $context);

        return $this->createX402Transaction($order, $transaction, $x402PaymentMethodId, $context);
    }

    private function createX402Transaction(
        OrderEntity $order,
        OrderTransactionEntity $previousTransaction,
        string $x402PaymentMethodId,
        Context $context,
    ): OrderTransactionEntity {
        $transactionId = Uuid::randomHex();

        $this->orderTransactionRepository->create([
            [
                'id' => $transactionId,
                'orderId' => $order->getId(),
                'orderVersionId' => $order->getVersionId(),
                'paymentMethodId' => $x402PaymentMethodId,
                'amount' => $previousTransaction->getAmount(),
                'stateId' => $this->initialStateIdLoader->get(OrderTransactionStates::STATE_MACHINE),
            ],
        ], $context);

        // The admin displays the primary transaction's state; point it at the
        // new transaction, exactly like core's SetPaymentOrderRoute does.
        $this->orderRepository->update([
            [
                'id' => $order->getId(),
                'versionId' => $order->getVersionId(),
                'primaryOrderTransactionId' => $transactionId,
            ],
        ], $context);

        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('paymentMethod');

        $created = $this->orderTransactionRepository->search($criteria, $context)->getEntities()->first();
        if (!$created instanceof OrderTransactionEntity) {
            throw X402Exception::orderNotFound($order->getId());
        }

        return $created;
    }
}

<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Checkout\Order;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\X402Payments\Core\X402\Exception\X402Exception;

class X402OrderResolver
{
    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
    ) {}

    public function resolve(string $orderId, string $salesChannelId, Context $context): OrderEntity
    {
        if (!Uuid::isValid($orderId)) {
            throw X402Exception::orderNotFound($orderId);
        }

        $criteria = new Criteria([$orderId]);
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('orderCustomer');

        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();
        if (!$order instanceof OrderEntity) {
            throw X402Exception::orderNotFound($orderId);
        }

        return $order;
    }

    /**
     * The latest transaction is the authoritative one for payment handling.
     */
    public function latestTransaction(OrderEntity $order): OrderTransactionEntity
    {
        $transactions = $order->getTransactions();
        $latest = $transactions?->last();
        if (!$latest instanceof OrderTransactionEntity) {
            throw X402Exception::orderNotFound($order->getId());
        }

        return $latest;
    }
}

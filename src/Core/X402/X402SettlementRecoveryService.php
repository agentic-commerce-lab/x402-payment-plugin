<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Swag\X402Payments\Core\Checkout\Payment\X402TransactionStateService;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Dto\X402RecoveryResult;

/**
 * Finishes the paid transition for sessions whose facilitator settlement
 * succeeded but whose Shopware state transition failed afterwards (spec
 * section 17.2). Recovery relies exclusively on persisted settlement
 * evidence - it never re-contacts the facilitator.
 */
class X402SettlementRecoveryService
{
    private const BATCH_LIMIT = 100;

    public function __construct(
        private readonly EntityRepository $sessionRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly X402TransactionStateService $transactionStateService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<X402RecoveryResult>
     */
    public function recover(Context $context, ?string $sessionId = null, bool $dryRun = false): array
    {
        $results = [];

        foreach ($this->findSettledSessions($sessionId, $context) as $session) {
            $results[] = $this->recoverSession($session, $dryRun, $context);
        }

        return $results;
    }

    /**
     * @return iterable<X402PaymentSessionEntity>
     */
    private function findSettledSessions(?string $sessionId, Context $context): iterable
    {
        $criteria = $sessionId === null ? new Criteria() : new Criteria([$sessionId]);
        $criteria->addFilter(new EqualsFilter('state', X402PaymentSessionStates::STATE_SETTLED));
        $criteria->setLimit(self::BATCH_LIMIT);

        /** @var iterable<X402PaymentSessionEntity> */
        return $this->sessionRepository->search($criteria, $context)->getEntities();
    }

    private function recoverSession(
        X402PaymentSessionEntity $session,
        bool $dryRun,
        Context $context,
    ): X402RecoveryResult {
        if (!$this->hasSettlementEvidence($session)) {
            return X402RecoveryResult::skipped($session, 'no successful settlement evidence persisted');
        }

        $transactionState = $this->loadTransactionState($session->getOrderTransactionId(), $context);

        if ($transactionState === null) {
            return X402RecoveryResult::skipped($session, 'order transaction not found');
        }

        if ($transactionState === OrderTransactionStates::STATE_PAID) {
            return X402RecoveryResult::skipped($session, 'order transaction is already paid');
        }

        if ($dryRun) {
            return X402RecoveryResult::recoverable($session, $transactionState);
        }

        return $this->markPaid($session, $transactionState, $context);
    }

    private function hasSettlementEvidence(X402PaymentSessionEntity $session): bool
    {
        $settleResponse = $session->getSettleResponseJson();

        return $settleResponse !== null && ($settleResponse['success'] ?? false) === true;
    }

    private function loadTransactionState(string $orderTransactionId, Context $context): ?string
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('stateMachineState');

        $transaction = $this->orderTransactionRepository->search($criteria, $context)->getEntities()->first();

        if (!$transaction instanceof OrderTransactionEntity) {
            return null;
        }

        return $transaction->getStateMachineState()?->getTechnicalName();
    }

    private function markPaid(
        X402PaymentSessionEntity $session,
        string $previousState,
        Context $context,
    ): X402RecoveryResult {
        try {
            $this->transactionStateService->markPaid($session->getOrderTransactionId(), $context);
        } catch (\Throwable $exception) {
            $this->logger->error('x402 settlement recovery failed.', [
                'paymentSessionId' => $session->getId(),
                'orderTransactionId' => $session->getOrderTransactionId(),
                'error' => $exception->getMessage(),
            ]);

            return X402RecoveryResult::failed($session, $exception->getMessage());
        }

        $this->logger->info('x402 settlement recovered: order transaction marked paid from persisted evidence.', [
            'paymentSessionId' => $session->getId(),
            'orderTransactionId' => $session->getOrderTransactionId(),
            'orderNumber' => $session->getOrderNumber(),
            'settlementTransactionHash' => $session->getSettlementTransactionHash(),
            'previousTransactionState' => $previousState,
        ]);

        return X402RecoveryResult::recovered($session, $previousState);
    }
}

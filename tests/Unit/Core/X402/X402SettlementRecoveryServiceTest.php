<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Core\X402;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Swag\X402Payments\Core\Checkout\Payment\X402TransactionStateService;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionCollection;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Dto\X402RecoveryResult;
use Swag\X402Payments\Core\X402\X402SettlementRecoveryService;
use Swag\X402Payments\Tests\Unit\Support\X402Fixtures;

/**
 * Spec 17.2 / 24.4 "paid-but-not-marked-paid": recovery marks the order
 * transaction paid exactly when persisted settlement evidence says the
 * facilitator succeeded and the transaction is not paid yet - and never
 * settles anything again.
 */
#[CoversClass(X402SettlementRecoveryService::class)]
final class X402SettlementRecoveryServiceTest extends TestCase
{
    private EntityRepository&MockObject $sessionRepository;

    private EntityRepository&MockObject $transactionRepository;

    private X402TransactionStateService&MockObject $transactionStateService;

    private X402SettlementRecoveryService $recoveryService;

    private Context $context;

    #[\Override]
    protected function setUp(): void
    {
        $this->sessionRepository = $this->createMock(EntityRepository::class);
        $this->transactionRepository = $this->createMock(EntityRepository::class);
        $this->transactionStateService = $this->createMock(X402TransactionStateService::class);
        $this->context = Context::createDefaultContext();

        $this->recoveryService = new X402SettlementRecoveryService(
            $this->sessionRepository,
            $this->transactionRepository,
            $this->transactionStateService,
            new NullLogger(),
        );
    }

    public function testSettledSessionWithUnpaidTransactionIsRecovered(): void
    {
        $session = $this->settledSession();
        $this->sessionSearchReturns($session);
        $this->transactionSearchReturns($session->getOrderTransactionId(), OrderTransactionStates::STATE_OPEN);

        $this->transactionStateService
            ->expects(self::once())
            ->method('markPaid')
            ->with($session->getOrderTransactionId(), $this->context);

        $results = $this->recoveryService->recover($this->context);

        self::assertCount(1, $results);
        self::assertSame(X402RecoveryResult::STATUS_RECOVERED, $results[0]->status);
    }

    public function testAlreadyPaidTransactionIsSkipped(): void
    {
        $session = $this->settledSession();
        $this->sessionSearchReturns($session);
        $this->transactionSearchReturns($session->getOrderTransactionId(), OrderTransactionStates::STATE_PAID);

        $this->transactionStateService->expects(self::never())->method('markPaid');

        $results = $this->recoveryService->recover($this->context);

        self::assertSame(X402RecoveryResult::STATUS_SKIPPED, $results[0]->status);
    }

    public function testSessionWithoutSuccessfulEvidenceIsNeverRecovered(): void
    {
        $session = $this->settledSession(['settleResponseJson' => ['success' => false, 'errorReason' => 'reorg']]);
        $this->sessionSearchReturns($session);

        $this->transactionRepository->expects(self::never())->method('search');
        $this->transactionStateService->expects(self::never())->method('markPaid');

        $results = $this->recoveryService->recover($this->context);

        self::assertSame(X402RecoveryResult::STATUS_SKIPPED, $results[0]->status);
    }

    public function testDryRunReportsWithoutTransitioning(): void
    {
        $session = $this->settledSession();
        $this->sessionSearchReturns($session);
        $this->transactionSearchReturns($session->getOrderTransactionId(), OrderTransactionStates::STATE_IN_PROGRESS);

        $this->transactionStateService->expects(self::never())->method('markPaid');

        $results = $this->recoveryService->recover($this->context, null, true);

        self::assertSame(X402RecoveryResult::STATUS_RECOVERABLE, $results[0]->status);
    }

    public function testFailingTransitionIsReportedNotThrown(): void
    {
        $session = $this->settledSession();
        $this->sessionSearchReturns($session);
        $this->transactionSearchReturns($session->getOrderTransactionId(), OrderTransactionStates::STATE_OPEN);

        $this->transactionStateService
            ->method('markPaid')
            ->willThrowException(new \RuntimeException('illegal transition'));

        $results = $this->recoveryService->recover($this->context);

        self::assertSame(X402RecoveryResult::STATUS_FAILED, $results[0]->status);
        self::assertTrue($results[0]->needsAttention());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function settledSession(array $overrides = []): X402PaymentSessionEntity
    {
        return X402Fixtures::session(array_merge([
            'state' => X402PaymentSessionStates::STATE_SETTLED,
            'settleResponseJson' => ['success' => true, 'transaction' => '0xhash'],
            'settlementTransactionHash' => '0xhash',
        ], $overrides));
    }

    private function sessionSearchReturns(X402PaymentSessionEntity ...$sessions): void
    {
        $this->sessionRepository
            ->method('search')
            ->willReturn(
                new EntitySearchResult(
                    'swag_x402_payment_session',
                    \count($sessions),
                    new X402PaymentSessionCollection(array_values($sessions)),
                    null,
                    new Criteria(),
                    $this->context,
                ),
            );
    }

    private function transactionSearchReturns(string $transactionId, string $technicalState): void
    {
        $state = new StateMachineStateEntity();
        $state->setId('f00d' . str_repeat('0', 28));
        $state->setTechnicalName($technicalState);

        $transaction = new OrderTransactionEntity();
        $transaction->setId($transactionId);
        $transaction->setUniqueIdentifier($transactionId);
        $transaction->setStateMachineState($state);

        $this->transactionRepository
            ->method('search')
            ->willReturn(
                new EntitySearchResult(
                    'order_transaction',
                    1,
                    new OrderTransactionCollection([$transaction]),
                    null,
                    new Criteria(),
                    $this->context,
                ),
            );
    }
}

<?php

declare(strict_types=1);

namespace Swag\X402Payments\ScheduledTask;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\StateMachine\StateMachineException;
use Swag\X402Payments\Core\Checkout\Payment\X402TransactionStateService;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;
use Swag\X402Payments\Core\X402\X402PaymentSessionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Expires stale payment sessions and cancels their still-open transactions
 * (spec section 17.1). Touches only x402 sessions and their transactions -
 * never orders or other plugins' records (spec section 26.1).
 */
#[AsMessageHandler(handles: ExpireX402PaymentSessionsTask::class)]
final class ExpireX402PaymentSessionsHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<\Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly X402PaymentSessionService $sessionService,
        private readonly X402TransactionStateService $transactionStateService,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    #[\Override]
    public function run(): void
    {
        $context = Context::createCLIContext();

        foreach ($this->sessionService->findExpired($this->clock->now(), $context) as $session) {
            $this->expire($session, $context);
        }
    }

    private function expire(X402PaymentSessionEntity $session, Context $context): void
    {
        $this->sessionService->markExpired($session->getId(), $context);

        try {
            $this->transactionStateService->markCancelled($session->getOrderTransactionId(), $context);
        } catch (StateMachineException $exception) {
            $this->exceptionLogger->warning('x402 session expired but transaction could not be cancelled.', [
                'paymentSessionId' => $session->getId(),
                'orderTransactionId' => $session->getOrderTransactionId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

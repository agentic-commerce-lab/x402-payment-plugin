<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\X402Payments\Core\Checkout\Payment\X402TransactionStateService;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Config\X402Config;
use Swag\X402Payments\Core\X402\Dto\FacilitatorResult;
use Swag\X402Payments\Core\X402\Dto\PaymentPayload;
use Swag\X402Payments\Core\X402\Dto\PaymentRequirements;
use Swag\X402Payments\Core\X402\Exception\X402Exception;

/**
 * Orchestrates verify + settle under a pessimistic row lock (spec sections
 * 12.5 and 15.2). The Shopware transaction becomes `paid` only after the
 * facilitator settled successfully - never after verification alone.
 */
class X402SettlementService
{
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        private readonly Connection $connection,
        private readonly X402PaymentSessionService $sessionService,
        private readonly X402PayloadValidator $payloadValidator,
        private readonly X402FacilitatorClient $facilitatorClient,
        private readonly X402TransactionStateService $transactionStateService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws \Throwable settlement failures are rethrown after rollback
     */
    public function settle(
        X402PaymentSessionEntity $session,
        PaymentPayload $payload,
        X402Config $config,
        Context $context,
    ): X402PaymentSessionEntity {
        $this->connection->beginTransaction();

        try {
            if ($this->lockSession($session->getId()) === X402PaymentSessionStates::STATE_SETTLED) {
                $this->connection->commit();

                return $this->sessionService->get($session->getId(), $context);
            }

            $this->process($session, $payload, $config, $context);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->rollbackIfActive();

            throw $exception;
        }

        return $this->sessionService->get($session->getId(), $context);
    }

    private function process(
        X402PaymentSessionEntity $session,
        PaymentPayload $payload,
        X402Config $config,
        Context $context,
    ): void {
        $this->assertNoReplay($session, $payload, $context);
        $this->payloadValidator->validate($payload, $session);

        $this->sessionService->markPayloadReceived($session->getId(), $payload, $context);
        $this->transactionStateService->markInProgress($session->getOrderTransactionId(), $context);

        $requirements = PaymentRequirements::fromArray($session->getRequirementsJson());
        $this->verify($session, $payload, $config, $requirements, $context);
        $settleResult = $this->doSettle($session, $payload, $config, $requirements, $context);

        // Settlement evidence must survive a failing state transition (spec
        // 24.5): commit it before markPaid so the recovery command can finish
        // the transition from the persisted settle response.
        $this->sessionService->markSettled($session, $settleResult, $context);
        $this->connection->commit();
        $this->connection->beginTransaction();

        $this->transactionStateService->markPaid($session->getOrderTransactionId(), $context);
    }

    private function lockSession(string $sessionId): string
    {
        $state = $this->connection->fetchOne('SELECT `state` FROM `swag_x402_payment_session` WHERE `id` = :id FOR UPDATE', [
            'id' => Uuid::fromHexToBytes($sessionId),
        ]);

        if (!\is_string($state)) {
            throw X402Exception::sessionNotFound($sessionId);
        }

        return $state;
    }

    private function assertNoReplay(X402PaymentSessionEntity $session, PaymentPayload $payload, Context $context): void
    {
        if ($this->sessionService->isPayloadHashUsedElsewhere($payload->payloadHash, $session->getId(), $context)) {
            throw X402Exception::payloadReplayed();
        }
    }

    private function verify(
        X402PaymentSessionEntity $session,
        PaymentPayload $payload,
        X402Config $config,
        PaymentRequirements $requirements,
        Context $context,
    ): void {
        $verifyResult = $this->facilitatorClient->verify($config, $payload, $requirements);

        if (!$verifyResult->success) {
            $this->logger->warning('x402 facilitator rejected verification.', [
                'paymentSessionId' => $session->getId(),
                'invalidReason' => $verifyResult->errorReason,
                'domainName' => $requirements->extra['name'] ?? null,
                'domainVersion' => $requirements->extra['version'] ?? null,
                'network' => $requirements->network,
                'asset' => $requirements->asset,
                'facilitatorRaw' => $verifyResult->raw,
            ]);

            $this->persistFailure($session, X402PaymentSessionStates::STATE_VERIFY_FAILED, $verifyResult, $context);

            throw X402Exception::verificationFailed($verifyResult->errorReason ?? 'unknown', [
                'name' => $requirements->extra['name'] ?? null,
                'version' => $requirements->extra['version'] ?? null,
                'network' => $requirements->network,
                'asset' => $requirements->asset,
            ]);
        }

        $this->sessionService->markVerified($session->getId(), $verifyResult, $context);
    }

    private function doSettle(
        X402PaymentSessionEntity $session,
        PaymentPayload $payload,
        X402Config $config,
        PaymentRequirements $requirements,
        Context $context,
    ): FacilitatorResult {
        $settleResult = $this->facilitatorClient->settle($config, $payload, $requirements);

        if (!$settleResult->success) {
            $this->persistFailure($session, X402PaymentSessionStates::STATE_SETTLEMENT_FAILED, $settleResult, $context);

            throw X402Exception::settlementFailed($settleResult->errorReason ?? 'unknown');
        }

        return $settleResult;
    }

    /**
     * Persists failure evidence even though the settlement flow aborts: the
     * surrounding transaction is committed and a fresh one opened, so the
     * exception thrown by the caller only rolls back an empty transaction.
     */
    private function persistFailure(
        X402PaymentSessionEntity $session,
        string $state,
        FacilitatorResult $result,
        Context $context,
    ): void {
        $this->sessionService->markFailed($session->getId(), $state, $result, $context);

        $this->connection->commit();
        $this->connection->beginTransaction();
    }

    private function rollbackIfActive(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }
}

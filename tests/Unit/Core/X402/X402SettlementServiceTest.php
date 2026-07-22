<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Core\X402;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Swag\X402Payments\Core\Checkout\Payment\X402TransactionStateService;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Dto\FacilitatorResult;
use Swag\X402Payments\Core\X402\Dto\PaymentRequirements;
use Swag\X402Payments\Core\X402\Exception\X402Exception;
use Swag\X402Payments\Core\X402\X402FacilitatorClient;
use Swag\X402Payments\Core\X402\X402PayloadValidator;
use Swag\X402Payments\Core\X402\X402PaymentSessionService;
use Swag\X402Payments\Core\X402\X402SettlementService;
use Swag\X402Payments\Tests\Unit\Support\X402Fixtures;

/**
 * Settlement orchestration invariants (spec 12.5, 15.2, 24.4): replay is
 * rejected before any facilitator call, duplicate retries are idempotent,
 * failed verification/settlement never marks the transaction paid, and
 * settlement evidence is persisted before the paid transition.
 */
// @mago-expect lint:too-many-methods
#[CoversClass(X402SettlementService::class)]
final class X402SettlementServiceTest extends TestCase
{
    private Connection&MockObject $connection;

    private X402PaymentSessionService&MockObject $sessionService;

    private X402PayloadValidator&MockObject $payloadValidator;

    private X402FacilitatorClient&MockObject $facilitatorClient;

    private X402TransactionStateService&MockObject $transactionStateService;

    private X402SettlementService $settlementService;

    private Context $context;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->sessionService = $this->createMock(X402PaymentSessionService::class);
        $this->payloadValidator = $this->createMock(X402PayloadValidator::class);
        $this->facilitatorClient = $this->createMock(X402FacilitatorClient::class);
        $this->transactionStateService = $this->createMock(X402TransactionStateService::class);
        $this->context = Context::createDefaultContext();

        $this->connection->method('isTransactionActive')->willReturn(true);

        $this->settlementService = new X402SettlementService(
            $this->connection,
            $this->sessionService,
            $this->payloadValidator,
            $this->facilitatorClient,
            $this->transactionStateService,
            new NullLogger(),
        );
    }

    public function testHappyPathVerifiesSettlesPersistsEvidenceThenMarksPaid(): void
    {
        $session = X402Fixtures::session();
        $this->lockReturnsState(X402PaymentSessionStates::STATE_REQUIREMENTS_ISSUED);
        $this->sessionService->method('isPayloadHashUsedElsewhere')->willReturn(false);
        $this->sessionService->method('get')->willReturn($session);

        $this->facilitatorClient->expects(self::once())->method('verify')->willReturn($this->facilitatorResult(true));
        $settleResult = $this->facilitatorResult(true, transactionHash: '0xhash');
        $this->facilitatorClient->expects(self::once())->method('settle')->willReturn($settleResult);

        $callOrder = [];
        $this->sessionService
            ->expects(self::once())
            ->method('markSettled')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'markSettled';
            });
        $this->connection
            ->expects(self::exactly(2))
            ->method('commit')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'commit';
            });
        $this->transactionStateService
            ->expects(self::once())
            ->method('markPaid')
            ->with($session->getOrderTransactionId(), $this->context)
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'markPaid';
            });

        $this->settlementService->settle($session, X402Fixtures::payload(), X402Fixtures::config(), $this->context);

        self::assertSame(['markSettled', 'commit', 'markPaid', 'commit'], $callOrder);
    }

    public function testDuplicateRetryOnSettledSessionReturnsStoredResultWithoutFacilitatorCalls(): void
    {
        $session = X402Fixtures::session(['state' => X402PaymentSessionStates::STATE_SETTLED]);
        $this->lockReturnsState(X402PaymentSessionStates::STATE_SETTLED);
        $this->sessionService->method('get')->willReturn($session);

        $this->facilitatorClient->expects(self::never())->method('verify');
        $this->facilitatorClient->expects(self::never())->method('settle');
        $this->transactionStateService->expects(self::never())->method('markPaid');

        $result = $this->settlementService->settle(
            $session,
            X402Fixtures::payload(),
            X402Fixtures::config(),
            $this->context,
        );

        self::assertSame($session, $result);
    }

    public function testReplayedPayloadIsRejectedBeforeAnyFacilitatorCall(): void
    {
        $session = X402Fixtures::session();
        $this->lockReturnsState(X402PaymentSessionStates::STATE_REQUIREMENTS_ISSUED);
        $this->sessionService->method('isPayloadHashUsedElsewhere')->willReturn(true);

        $this->facilitatorClient->expects(self::never())->method('verify');
        $this->facilitatorClient->expects(self::never())->method('settle');
        $this->transactionStateService->expects(self::never())->method('markPaid');
        $this->connection->expects(self::once())->method('rollBack');

        try {
            $this->settlementService->settle($session, X402Fixtures::payload(), X402Fixtures::config(), $this->context);
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::PAYLOAD_REPLAYED, $exception->getErrorCode());
        }
    }

    public function testFailedVerificationNeverReachesSettleOrPaid(): void
    {
        $session = X402Fixtures::session();
        $this->lockReturnsState(X402PaymentSessionStates::STATE_REQUIREMENTS_ISSUED);
        $this->sessionService->method('isPayloadHashUsedElsewhere')->willReturn(false);

        $this->facilitatorClient
            ->method('verify')
            ->willReturn($this->facilitatorResult(false, errorReason: 'bad signature'));

        $this->facilitatorClient->expects(self::never())->method('settle');
        $this->transactionStateService->expects(self::never())->method('markPaid');
        $this->sessionService
            ->expects(self::once())
            ->method('markFailed')
            ->with($session->getId(), X402PaymentSessionStates::STATE_VERIFY_FAILED, self::anything(), $this->context);

        try {
            $this->settlementService->settle($session, X402Fixtures::payload(), X402Fixtures::config(), $this->context);
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::VERIFICATION_FAILED, $exception->getErrorCode());
        }
    }

    public function testFailedSettlementNeverMarksPaid(): void
    {
        $session = X402Fixtures::session();
        $this->lockReturnsState(X402PaymentSessionStates::STATE_REQUIREMENTS_ISSUED);
        $this->sessionService->method('isPayloadHashUsedElsewhere')->willReturn(false);

        $this->facilitatorClient->method('verify')->willReturn($this->facilitatorResult(true));
        $this->facilitatorClient
            ->method('settle')
            ->willReturn($this->facilitatorResult(false, errorReason: 'chain error'));

        $this->transactionStateService->expects(self::never())->method('markPaid');
        $this->sessionService->expects(self::never())->method('markSettled');
        $this->sessionService
            ->expects(self::once())
            ->method('markFailed')
            ->with(
                $session->getId(),
                X402PaymentSessionStates::STATE_SETTLEMENT_FAILED,
                self::anything(),
                $this->context,
            );

        try {
            $this->settlementService->settle($session, X402Fixtures::payload(), X402Fixtures::config(), $this->context);
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::SETTLEMENT_FAILED, $exception->getErrorCode());
        }
    }

    public function testFacilitatorOutageNeverMarksPaid(): void
    {
        $session = X402Fixtures::session();
        $this->lockReturnsState(X402PaymentSessionStates::STATE_REQUIREMENTS_ISSUED);
        $this->sessionService->method('isPayloadHashUsedElsewhere')->willReturn(false);

        $this->facilitatorClient->method('verify')->willThrowException(X402Exception::facilitatorUnavailable());

        $this->transactionStateService->expects(self::never())->method('markPaid');
        $this->sessionService->expects(self::never())->method('markSettled');
        $this->connection->expects(self::once())->method('rollBack');

        $this->expectException(X402Exception::class);

        $this->settlementService->settle($session, X402Fixtures::payload(), X402Fixtures::config(), $this->context);
    }

    /**
     * Recovery precondition (spec 24.5): when the paid transition fails after
     * successful settlement, the settlement evidence has already been
     * committed so x402:recover-settlements can finish the job.
     */
    public function testSettlementEvidenceIsCommittedBeforeFailingPaidTransition(): void
    {
        $session = X402Fixtures::session();
        $this->lockReturnsState(X402PaymentSessionStates::STATE_REQUIREMENTS_ISSUED);
        $this->sessionService->method('isPayloadHashUsedElsewhere')->willReturn(false);

        $this->facilitatorClient->method('verify')->willReturn($this->facilitatorResult(true));
        $this->facilitatorClient
            ->method('settle')
            ->willReturn($this->facilitatorResult(true, transactionHash: '0xhash'));

        $evidenceCommitted = false;
        $this->sessionService->expects(self::once())->method('markSettled');
        $this->connection
            ->expects(self::once())
            ->method('commit')
            ->willReturnCallback(function () use (&$evidenceCommitted): void {
                $evidenceCommitted = true;
            });
        $this->transactionStateService
            ->method('markPaid')
            ->willReturnCallback(static function () use (&$evidenceCommitted): void {
                self::assertTrue($evidenceCommitted, 'evidence must be committed before the paid transition');

                throw new \RuntimeException('state machine unavailable');
            });

        $this->expectException(\RuntimeException::class);

        $this->settlementService->settle($session, X402Fixtures::payload(), X402Fixtures::config(), $this->context);
    }

    /**
     * Spec: a facilitator verify-rejection surfaces the advertised EIP-712
     * domain (name/version/network/asset) in the exception parameters so an
     * agent can self-diagnose a domain mismatch, without corrupting the
     * `{{ reason }}` message placeholder.
     */
    public function testVerificationFailureExposesAdvertisedDomainInErrorParameters(): void
    {
        $requirements = new PaymentRequirements(
            scheme: 'exact',
            network: 'base',
            maxAmountRequired: X402Fixtures::ATOMIC_AMOUNT,
            asset: '0x8335176BeA1E27078bA0Ba0F44f8A6e1E00cBBB',
            payTo: X402Fixtures::MERCHANT_WALLET,
            resource: X402Fixtures::RESOURCE_URL,
            description: 'Shopware order 10042',
            maxTimeoutSeconds: 300,
            extra: ['name' => 'USDC', 'version' => '2'],
        );
        $session = X402Fixtures::session(['requirementsJson' => $requirements->toArray()]);
        $this->lockReturnsState(X402PaymentSessionStates::STATE_REQUIREMENTS_ISSUED);
        $this->sessionService->method('isPayloadHashUsedElsewhere')->willReturn(false);

        $this->facilitatorClient
            ->method('verify')
            ->willReturn($this->facilitatorResult(false, errorReason: 'invalid_payload'));

        $this->facilitatorClient->expects(self::never())->method('settle');
        $this->transactionStateService->expects(self::never())->method('markPaid');
        $this->sessionService
            ->expects(self::once())
            ->method('markFailed')
            ->with($session->getId(), X402PaymentSessionStates::STATE_VERIFY_FAILED, self::anything(), $this->context);

        try {
            $this->settlementService->settle($session, X402Fixtures::payload(), X402Fixtures::config(), $this->context);
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::VERIFICATION_FAILED, $exception->getErrorCode());
            $params = $exception->getParameters();
            self::assertSame('invalid_payload', $params['reason']);
            self::assertArrayHasKey('domain', $params);
            self::assertSame('USDC', $params['domain']['name']);
            self::assertSame('2', $params['domain']['version']);
            self::assertSame('base', $params['domain']['network']);
        }
    }

    private function lockReturnsState(string $state): void
    {
        $this->connection->method('fetchOne')->willReturn($state);
    }

    private function facilitatorResult(
        bool $success,
        ?string $transactionHash = null,
        ?string $errorReason = null,
    ): FacilitatorResult {
        return new FacilitatorResult(
            success: $success,
            payer: X402Fixtures::PAYER_WALLET,
            transactionHash: $transactionHash,
            errorReason: $errorReason,
            raw: ['success' => $success],
        );
    }
}

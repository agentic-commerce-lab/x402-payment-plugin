<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402;

use Psr\Clock\ClockInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionCollection;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionEntity;
use Swag\X402Payments\Core\Content\X402PaymentSession\X402PaymentSessionStates;
use Swag\X402Payments\Core\X402\Dto\FacilitatorResult;
use Swag\X402Payments\Core\X402\Dto\PaymentPayload;
use Swag\X402Payments\Core\X402\Dto\X402Quote;
use Swag\X402Payments\Core\X402\Dto\X402SessionContext;
use Swag\X402Payments\Core\X402\Exception\X402Exception;

// Cohesive persistence gateway for the payment session aggregate.
// @mago-expect lint:too-many-methods
class X402PaymentSessionService
{
    /**
     * @param EntityRepository<X402PaymentSessionCollection> $sessionRepository
     */
    public function __construct(
        private readonly EntityRepository $sessionRepository,
        private readonly X402RequirementBuilder $requirementBuilder,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * One session exists per order transaction. Active quotes are reused for
     * idempotent retries; expired quotes are refreshed in place (spec phase 2).
     */
    public function getOrCreate(X402SessionContext $sessionContext, Context $context): X402PaymentSessionEntity
    {
        $existing = $this->findByOrderTransactionId($sessionContext->transaction->getId(), $context);

        if ($existing === null) {
            return $this->create($sessionContext, $context);
        }

        if ($existing->isSettled() || !$existing->isExpired($this->clock->now())) {
            return $existing;
        }

        return $this->refresh($existing, $sessionContext, $context);
    }

    public function get(string $sessionId, Context $context): X402PaymentSessionEntity
    {
        $session = $this->sessionRepository
            ->search(new Criteria([$sessionId]), $context)
            ->getEntities()
            ->first();

        if (!$session instanceof X402PaymentSessionEntity) {
            throw X402Exception::sessionNotFound($sessionId);
        }

        return $session;
    }

    public function findByOrderTransactionId(string $orderTransactionId, Context $context): ?X402PaymentSessionEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderTransactionId', $orderTransactionId));
        $criteria->setLimit(1);

        $session = $this->sessionRepository->search($criteria, $context)->getEntities()->first();

        return $session instanceof X402PaymentSessionEntity ? $session : null;
    }

    public function isPayloadHashUsedElsewhere(string $payloadHash, string $sessionId, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('paymentPayloadHash', $payloadHash));
        $criteria->setLimit(2);

        foreach ($this->sessionRepository->searchIds($criteria, $context)->getIds() as $id) {
            if ($id !== $sessionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $sessionId, array $data, Context $context): void
    {
        $this->sessionRepository->update([array_merge(['id' => $sessionId], $data)], $context);
    }

    public function markExpired(string $sessionId, Context $context): void
    {
        $this->update($sessionId, ['state' => X402PaymentSessionStates::STATE_EXPIRED], $context);
    }

    public function markPayloadReceived(string $sessionId, PaymentPayload $payload, Context $context): void
    {
        $this->update(
            $sessionId,
            [
                'state' => X402PaymentSessionStates::STATE_PAYLOAD_RECEIVED,
                'paymentPayloadHash' => $payload->payloadHash,
                'payerWallet' => $payload->from,
            ],
            $context,
        );
    }

    public function markVerified(string $sessionId, FacilitatorResult $verifyResult, Context $context): void
    {
        $this->update(
            $sessionId,
            [
                'state' => X402PaymentSessionStates::STATE_VERIFIED,
                'verifyResponseJson' => $verifyResult->raw,
            ],
            $context,
        );
    }

    public function markSettled(
        X402PaymentSessionEntity $session,
        FacilitatorResult $settleResult,
        Context $context,
    ): void {
        $this->update(
            $session->getId(),
            [
                'state' => X402PaymentSessionStates::STATE_SETTLED,
                'settleResponseJson' => $settleResult->raw,
                'settlementTransactionHash' => $settleResult->transactionHash,
                'payerWallet' => $settleResult->payer ?? $session->getPayerWallet(),
                'paidAt' => $this->clock->now(),
            ],
            $context,
        );
    }

    public function markFailed(string $sessionId, string $state, FacilitatorResult $result, Context $context): void
    {
        $responseField = $state === X402PaymentSessionStates::STATE_VERIFY_FAILED
            ? 'verifyResponseJson'
            : 'settleResponseJson';

        $this->update(
            $sessionId,
            [
                'state' => $state,
                $responseField => $result->raw,
                'failureReason' => $result->errorReason,
            ],
            $context,
        );
    }

    /**
     * @return iterable<X402PaymentSessionEntity>
     */
    public function findExpired(\DateTimeInterface $now, Context $context): iterable
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('state', X402PaymentSessionStates::PAYABLE_STATES));
        $criteria->addFilter(new RangeFilter('expiresAt', [
            RangeFilter::LT => $now->format(\DateTimeInterface::ATOM),
        ]));
        $criteria->setLimit(100);

        return $this->sessionRepository->search($criteria, $context)->getEntities();
    }

    private function create(X402SessionContext $sessionContext, Context $context): X402PaymentSessionEntity
    {
        $sessionId = Uuid::randomHex();
        $quote = $this->requirementBuilder->build($sessionContext, $sessionId);

        $this->sessionRepository->create([
            array_merge(['id' => $sessionId], $this->identityData($sessionContext), $this->quoteData($quote)),
        ], $context);

        return $this->get($sessionId, $context);
    }

    private function refresh(
        X402PaymentSessionEntity $session,
        X402SessionContext $sessionContext,
        Context $context,
    ): X402PaymentSessionEntity {
        $quote = $this->requirementBuilder->build($sessionContext, $session->getId());

        $this->update($session->getId(), $this->quoteData($quote), $context);

        return $this->get($session->getId(), $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function identityData(X402SessionContext $sessionContext): array
    {
        return [
            'salesChannelId' => $sessionContext->salesChannelId,
            'contextTokenHash' => $sessionContext->contextTokenHash,
            'ownershipProof' => $sessionContext->ownershipProof,
            'deepLinkCodeHash' => $sessionContext->deepLinkCodeHash,
            'orderId' => $sessionContext->order->getId(),
            'orderTransactionId' => $sessionContext->transaction->getId(),
            'orderNumber' => $sessionContext->order->getOrderNumber() ?? '',
            'idempotencyKeyHash' => $sessionContext->idempotencyKeyHash,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteData(X402Quote $quote): array
    {
        return [
            'state' => X402PaymentSessionStates::STATE_REQUIREMENTS_ISSUED,
            'scheme' => $quote->requirements->scheme,
            'network' => $quote->requirements->network,
            'asset' => $quote->requirements->asset,
            'assetSymbol' => $quote->assetSymbol,
            'assetDecimals' => $quote->assetDecimals,
            'shopwareCurrency' => $quote->shopwareCurrency,
            'shopwareAmount' => $quote->shopwareAmount,
            'paymentAmountAtomic' => $quote->atomicAmount,
            'payTo' => $quote->requirements->payTo,
            'resourceUrl' => $quote->requirements->resource,
            'quoteHash' => $quote->quoteHash,
            'requirementsJson' => $quote->requirements->toArray(),
            'expiresAt' => $quote->expiresAt,
            'failureReason' => null,
        ];
    }
}

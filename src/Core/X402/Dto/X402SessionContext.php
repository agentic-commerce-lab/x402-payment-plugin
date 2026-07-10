<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402\Dto;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Swag\X402Payments\Core\X402\Config\X402Config;

final readonly class X402SessionContext
{
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        public OrderEntity $order,
        public OrderTransactionEntity $transaction,
        public X402Config $config,
        public string $salesChannelId,
        public string $resourceUrl,
        public string $ownershipProof,
        public ?string $contextTokenHash,
        public ?string $deepLinkCodeHash,
        public string $idempotencyKeyHash,
    ) {}
}

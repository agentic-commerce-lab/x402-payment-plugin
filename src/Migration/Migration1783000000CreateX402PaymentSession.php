<?php

declare(strict_types=1);

namespace Swag\X402Payments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
final class Migration1783000000CreateX402PaymentSession extends MigrationStep
{
    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1783000000;
    }

    #[\Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_x402_payment_session` (
                `id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `context_token_hash` VARCHAR(128) NULL,
                `ownership_proof` VARCHAR(32) NOT NULL,
                `deep_link_code_hash` CHAR(64) NULL,
                `order_id` BINARY(16) NOT NULL,
                `order_transaction_id` BINARY(16) NOT NULL,
                `order_number` VARCHAR(64) NOT NULL,
                `state` VARCHAR(32) NOT NULL,
                `scheme` VARCHAR(32) NOT NULL,
                `network` VARCHAR(64) NOT NULL,
                `asset` VARCHAR(128) NOT NULL,
                `asset_symbol` VARCHAR(32) NOT NULL,
                `asset_decimals` INT(11) NOT NULL,
                `shopware_currency` VARCHAR(8) NOT NULL,
                `shopware_amount` DOUBLE NOT NULL,
                `payment_amount_atomic` VARCHAR(78) NOT NULL,
                `pay_to` VARCHAR(128) NOT NULL,
                `resource_url` VARCHAR(1024) NOT NULL,
                `quote_hash` CHAR(64) NOT NULL,
                `requirements_json` JSON NOT NULL,
                `payment_payload_hash` CHAR(64) NULL,
                `payer_wallet` VARCHAR(128) NULL,
                `verify_response_json` JSON NULL,
                `settle_response_json` JSON NULL,
                `settlement_transaction_hash` VARCHAR(128) NULL,
                `idempotency_key_hash` CHAR(64) NOT NULL,
                `expires_at` DATETIME(3) NOT NULL,
                `paid_at` DATETIME(3) NULL,
                `failure_reason` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.swag_x402_payment_session.order_transaction_id` (`order_transaction_id`),
                UNIQUE KEY `uniq.swag_x402_payment_session.payment_payload_hash` (`payment_payload_hash`),
                KEY `idx.swag_x402_payment_session.state_expires_at` (`state`, `expires_at`),
                KEY `idx.swag_x402_payment_session.order_id` (`order_id`),
                KEY `idx.swag_x402_payment_session.settlement_tx` (`settlement_transaction_hash`),
                CONSTRAINT `fk.swag_x402_payment_session.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT `fk.swag_x402_payment_session.order_id`
                    FOREIGN KEY (`order_id`) REFERENCES `order` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[\Override]
    public function updateDestructive(Connection $connection): void
    {
        // Keep settlement evidence for audit and recovery (spec 17.2).
    }
}

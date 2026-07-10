<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Content\X402PaymentSession;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

class X402PaymentSessionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'swag_x402_payment_session';

    #[\Override]
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    #[\Override]
    public function getEntityClass(): string
    {
        return X402PaymentSessionEntity::class;
    }

    #[\Override]
    public function getCollectionClass(): string
    {
        return X402PaymentSessionCollection::class;
    }

    #[\Override]
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required(), new ApiAware()),
            (new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class))->addFlags(
                new Required(),
            ),
            new StringField('context_token_hash', 'contextTokenHash'),
            (new StringField('ownership_proof', 'ownershipProof'))->addFlags(new Required()),
            new StringField('deep_link_code_hash', 'deepLinkCodeHash'),
            (new IdField('order_id', 'orderId'))->addFlags(new Required()),
            (new IdField('order_transaction_id', 'orderTransactionId'))->addFlags(new Required()),
            (new StringField('order_number', 'orderNumber'))->addFlags(new Required()),
            (new StringField('state', 'state'))->addFlags(new Required()),
            (new StringField('scheme', 'scheme'))->addFlags(new Required()),
            (new StringField('network', 'network'))->addFlags(new Required()),
            (new StringField('asset', 'asset'))->addFlags(new Required()),
            (new StringField('asset_symbol', 'assetSymbol'))->addFlags(new Required()),
            (new IntField('asset_decimals', 'assetDecimals'))->addFlags(new Required()),
            (new StringField('shopware_currency', 'shopwareCurrency'))->addFlags(new Required()),
            (new FloatField('shopware_amount', 'shopwareAmount'))->addFlags(new Required()),
            (new StringField('payment_amount_atomic', 'paymentAmountAtomic'))->addFlags(new Required()),
            (new StringField('pay_to', 'payTo'))->addFlags(new Required()),
            (new StringField('resource_url', 'resourceUrl', 1024))->addFlags(new Required()),
            (new StringField('quote_hash', 'quoteHash'))->addFlags(new Required()),
            (new JsonField('requirements_json', 'requirementsJson'))->addFlags(new Required()),
            new StringField('payment_payload_hash', 'paymentPayloadHash'),
            new StringField('payer_wallet', 'payerWallet'),
            new JsonField('verify_response_json', 'verifyResponseJson'),
            new JsonField('settle_response_json', 'settleResponseJson'),
            new StringField('settlement_transaction_hash', 'settlementTransactionHash'),
            (new StringField('idempotency_key_hash', 'idempotencyKeyHash'))->addFlags(new Required()),
            (new DateTimeField('expires_at', 'expiresAt'))->addFlags(new Required()),
            new DateTimeField('paid_at', 'paidAt'),
            new LongTextField('failure_reason', 'failureReason'),
        ]);
    }
}

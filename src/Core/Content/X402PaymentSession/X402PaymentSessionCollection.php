<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Content\X402PaymentSession;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<X402PaymentSessionEntity>
 */
class X402PaymentSessionCollection extends EntityCollection
{
    #[\Override]
    protected function getExpectedClass(): string
    {
        return X402PaymentSessionEntity::class;
    }
}

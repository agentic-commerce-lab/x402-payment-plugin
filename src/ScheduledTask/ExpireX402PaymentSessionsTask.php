<?php

declare(strict_types=1);

namespace Swag\X402Payments\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ExpireX402PaymentSessionsTask extends ScheduledTask
{
    #[\Override]
    public static function getTaskName(): string
    {
        return 'swag_x402.expire_payment_sessions';
    }

    #[\Override]
    public static function getDefaultInterval(): int
    {
        return 300;
    }
}

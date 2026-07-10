<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Checkout\Payment;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\X402Payments\SwagX402Payments;

/**
 * Creates the x402 payment method on install and toggles activation on
 * activate/deactivate. The method is never deleted on uninstall (spec
 * section 3.4).
 */
class X402PaymentMethodInstaller
{
    public const TECHNICAL_NAME = 'swag_x402_agentic';

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Payment\PaymentMethodCollection> $paymentMethodRepository
     */
    public function __construct(
        private readonly EntityRepository $paymentMethodRepository,
        private readonly PluginIdProvider $pluginIdProvider,
    ) {}

    public function install(Context $context): void
    {
        if ($this->findPaymentMethodId($context) !== null) {
            return;
        }

        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(SwagX402Payments::class, $context);

        $this->paymentMethodRepository->create([
            [
                'id' => Uuid::randomHex(),
                'technicalName' => self::TECHNICAL_NAME,
                'handlerIdentifier' => X402PaymentHandler::class,
                'name' => 'x402 Agentic Payment',
                'description' => 'Pay a Shopware order transaction through the x402 protocol.',
                'pluginId' => $pluginId,
                'afterOrderEnabled' => true,
                'active' => false,
            ],
        ], $context);
    }

    public function setActive(bool $active, Context $context): void
    {
        $paymentMethodId = $this->findPaymentMethodId($context);
        if ($paymentMethodId === null) {
            return;
        }

        $this->paymentMethodRepository->update([
            [
                'id' => $paymentMethodId,
                'active' => $active,
            ],
        ], $context);
    }

    private function findPaymentMethodId(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', X402PaymentHandler::class));
        $criteria->setLimit(1);

        return $this->paymentMethodRepository->searchIds($criteria, $context)->firstId();
    }
}

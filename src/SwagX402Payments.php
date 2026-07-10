<?php

declare(strict_types=1);

namespace Swag\X402Payments;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Swag\X402Payments\Core\Checkout\Payment\X402PaymentMethodInstaller;

// Inherited bundle properties are initialized by the Shopware kernel.
// @mago-expect analysis:missing-constructor
final class SwagX402Payments extends Plugin
{
    #[\Override]
    public function install(InstallContext $installContext): void
    {
        $this->paymentMethodInstaller()->install($installContext->getContext());
    }

    #[\Override]
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->paymentMethodInstaller()->setActive(false, $deactivateContext->getContext());
    }

    #[\Override]
    public function uninstall(UninstallContext $uninstallContext): void
    {
        // Deactivate, never delete: orders keep referencing the method (spec 6.1).
        $this->paymentMethodInstaller()->setActive(false, $uninstallContext->getContext());
    }

    private function paymentMethodInstaller(): X402PaymentMethodInstaller
    {
        $paymentMethodRepository = $this->container?->get('payment_method.repository');
        $pluginIdProvider = $this->container?->get(PluginIdProvider::class);

        \assert($paymentMethodRepository instanceof EntityRepository, 'payment_method.repository must be available');
        \assert($pluginIdProvider instanceof PluginIdProvider, 'PluginIdProvider must be available');

        /** @var EntityRepository<\Shopware\Core\Checkout\Payment\PaymentMethodCollection> $paymentMethodRepository */
        return new X402PaymentMethodInstaller($paymentMethodRepository, $pluginIdProvider);
    }
}

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
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

// Inherited bundle properties are initialized by the Shopware kernel.
// @mago-expect analysis:missing-constructor
final class SwagX402Payments extends Plugin
{
    /**
     * @throws \Exception when a conditional service definition file cannot be loaded
     */
    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Optional UCP bridge (spec 26.4): the plugin must install and run
        // without SwagAgenticCommerce / ucp-php-sdk, so these services load
        // only when the extension points exist.
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/Resources/config'));

        if (interface_exists('Ucp\Sdk\Contract\PaymentHandlerInterface')) {
            $loader->load('services_ucp.xml');
        }

        if (interface_exists('Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizerInterface')) {
            $loader->load('services_ucp_authorizer.xml');
        }
    }

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

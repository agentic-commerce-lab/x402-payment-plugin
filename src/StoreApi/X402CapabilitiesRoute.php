<?php

declare(strict_types=1);

namespace Swag\X402Payments\StoreApi;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\X402Payments\Core\Checkout\Payment\X402PaymentMethodInstaller;
use Swag\X402Payments\Core\X402\Config\X402ConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Discovery endpoint for agents (spec section 7.1).
 */
#[Route(defaults: ['_routeScope' => ['store-api']])]
class X402CapabilitiesRoute
{
    public function __construct(
        private readonly X402ConfigService $configService,
    ) {}

    #[Route(path: '/store-api/x402/capabilities', name: 'store-api.x402.capabilities', methods: ['GET'])]
    public function capabilities(SalesChannelContext $salesChannelContext): JsonResponse
    {
        $config = $this->configService->getConfig($salesChannelContext->getSalesChannelId());
        $available = $config->isComplete();

        return new JsonResponse([
            'x402Version' => 1,
            'schemes' => $available ? ['exact'] : [],
            'networks' => $available
                ? [
                    [
                        'network' => $config->network,
                        'asset' => $config->assetAddress,
                        'symbol' => $config->assetSymbol,
                        'decimals' => $config->assetDecimals,
                    ],
                ] : [],
            'limits' => [
                'minAmount' => number_format($config->minOrderAmount, 2, '.', ''),
                'maxAmount' => number_format($config->maxOrderAmount, 2, '.', ''),
            ],
            'currency' => $config->supportedCurrency,
            'paymentMethodTechnicalName' => X402PaymentMethodInstaller::TECHNICAL_NAME,
        ]);
    }
}

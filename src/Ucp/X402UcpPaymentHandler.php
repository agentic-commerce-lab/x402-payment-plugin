<?php

declare(strict_types=1);

namespace Swag\X402Payments\Ucp;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Swag\X402Payments\Core\Checkout\Payment\X402PaymentMethodResolver;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * UCP payment handler descriptor for x402 (spec section 26.4, Tier 3).
 *
 * x402 is a DELEGATED payment scheme: per-order signed EIP-3009
 * authorizations, no credential vaulting. supportsTokenization() is
 * therefore false by contract - never fake tokenization. The handler is only
 * advertised in /.well-known/ucp when the SwagAgenticCommerce sales channel
 * opts in via `advertiseDelegatedPaymentHandlers`.
 *
 * Registered conditionally: only when ucp-php-sdk is installed (see
 * SwagX402Payments::build()).
 */
class X402UcpPaymentHandler implements PaymentHandlerInterface
{
    public const HANDLER_ID = 'com.shopware.x402';

    public const INSTRUMENT_TYPE = 'x402';

    /**
     * @param EntityRepository<SalesChannelDomainCollection> $salesChannelDomainRepository
     */
    public function __construct(
        private readonly X402PaymentMethodResolver $paymentMethodResolver,
        private readonly EntityRepository $salesChannelDomainRepository,
    ) {}

    public function id(): string
    {
        return self::HANDLER_ID;
    }

    public function describe(RequestContext $context): PaymentHandlerDescriptor
    {
        return new PaymentHandlerDescriptor(
            $this->id(),
            $this->id(),
            '2026-07-13',
            // Only URLs that actually resolve: the open x402 protocol
            // specification, and this shop's live machine-readable x402
            // capabilities endpoint (schemes, networks, assets, limits).
            'https://github.com/coinbase/x402/blob/main/specs/x402-specification-v1.md',
            $this->capabilitiesUrl($context),
            [],
            array_filter(
                [
                    'tokenization' => false,
                    'scheme' => 'exact',
                    'instrument_type' => self::INSTRUMENT_TYPE,
                    // Store API routes require this header. It is public client
                    // identification (embedded in every storefront page), not a
                    // credential; with it an agent can call the capabilities
                    // endpoint and the pay route straight from discovery.
                    'access_key' => $this->accessKeyForBase($this->baseUrl($context)),
                    'description' =>
                        'x402 protocol payments (HTTP 402, signed EIP-3009 stablecoin authorizations). '
                            . 'Settlement runs through the shop\'s x402 pay route; see the extra.x402 object on completed checkouts.',
                ],
                static fn(mixed $value): bool => $value !== null,
            ),
        );
    }

    /**
     * The profile builder passes the shop base URI as the context host; other
     * call sites may pass a bare hostname.
     */
    private function baseUrl(RequestContext $context): string
    {
        $base = rtrim($context->host, '/');
        if ($base !== '' && !str_starts_with($base, 'http://') && !str_starts_with($base, 'https://')) {
            $base = 'https://' . $base;
        }

        return $base;
    }

    private function capabilitiesUrl(RequestContext $context): string
    {
        return $this->baseUrl($context) . '/store-api/x402/capabilities';
    }

    private function accessKeyForBase(string $baseUrl): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('url', $baseUrl));
        $criteria->addAssociation('salesChannel');
        $criteria->setLimit(1);

        $domain = $this->salesChannelDomainRepository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities()
            ->first();

        if (!$domain instanceof SalesChannelDomainEntity) {
            return null;
        }

        return $domain->getSalesChannel()?->getAccessKey();
    }

    /**
     * @return array{paymentMethodId: string, token: string}
     */
    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
    {
        return [
            'paymentMethodId' => $this->paymentMethodResolver->getX402PaymentMethodId(Context::createDefaultContext()),
            'token' => hash('sha256', (string) json_encode($instrument->credential)),
        ];
    }

    public function supportsTokenization(): bool
    {
        return false;
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
    {
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Checkout\Payment;

use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Swag\X402Payments\Core\X402\Exception\X402Exception;

class X402PaymentMethodResolver
{
    /**
     * @param EntityRepository<PaymentMethodCollection> $paymentMethodRepository
     */
    public function __construct(
        private readonly EntityRepository $paymentMethodRepository,
    ) {}

    public function getX402PaymentMethodId(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', X402PaymentHandler::class));
        $criteria->setLimit(1);

        $id = $this->paymentMethodRepository->searchIds($criteria, $context)->firstId();
        if ($id === null) {
            throw X402Exception::notConfigured('payment method missing');
        }

        return $id;
    }
}

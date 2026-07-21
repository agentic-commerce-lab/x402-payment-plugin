<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\X402;

use Psr\Log\LoggerInterface;
use Swag\X402Payments\Core\X402\Config\X402Config;
use Swag\X402Payments\Core\X402\Dto\FacilitatorResult;
use Swag\X402Payments\Core\X402\Dto\PaymentPayload;
use Swag\X402Payments\Core\X402\Dto\PaymentRequirements;
use Swag\X402Payments\Core\X402\Exception\X402Exception;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class X402FacilitatorClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function verify(
        X402Config $config,
        PaymentPayload $payload,
        PaymentRequirements $requirements,
    ): FacilitatorResult {
        $response = $this->post($config, '/verify', $payload, $requirements);

        return FacilitatorResult::fromVerifyResponse($response);
    }

    public function settle(
        X402Config $config,
        PaymentPayload $payload,
        PaymentRequirements $requirements,
    ): FacilitatorResult {
        $response = $this->post($config, '/settle', $payload, $requirements);

        return FacilitatorResult::fromSettleResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function post(
        X402Config $config,
        string $path,
        PaymentPayload $payload,
        PaymentRequirements $requirements,
    ): array {
        $headers = ['Content-Type' => 'application/json'];
        if ($config->facilitatorApiKey !== null) {
            $headers['Authorization'] = 'Bearer ' . $config->facilitatorApiKey;
        }

        try {
            $response = $this->httpClient->request('POST', $config->facilitatorBaseUrl . $path, [
                'headers' => $headers,
                'timeout' => $config->facilitatorTimeoutSeconds,
                'json' => [
                    'x402Version' => 1,
                    'paymentPayload' => $payload->toFacilitatorArray(),
                    'paymentRequirements' => $requirements->toArray(),
                ],
            ]);

            $data = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            $this->logger->error('x402 facilitator call failed.', [
                'path' => $path,
                'payloadHash' => $payload->payloadHash,
                'error' => $exception->getMessage(),
            ]);

            throw X402Exception::facilitatorUnavailable();
        }

        $this->logger->info('x402 facilitator call completed.', [
            'path' => $path,
            'payloadHash' => $payload->payloadHash,
            'network' => $requirements->network,
            'asset' => $requirements->asset,
            'domainName' => $requirements->extra['name'] ?? null,
            'domainVersion' => $requirements->extra['version'] ?? null,
        ]);

        /** @var array<string, mixed> $data */
        return $data;
    }
}

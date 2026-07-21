<?php

declare(strict_types=1);

namespace Swag\X402Payments\Tests\Unit\Core\X402;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swag\X402Payments\Core\X402\Exception\X402Exception;
use Swag\X402Payments\Core\X402\X402CdpJwtFactory;
use Swag\X402Payments\Core\X402\X402FacilitatorClient;
use Swag\X402Payments\Tests\Unit\Support\X402Fixtures;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Contract tests against a mock facilitator (spec 24.3 level 3): exact
 * request shape for /verify and /settle, and the failure-mode rule that a
 * facilitator outage or garbage response can never look like success.
 */
#[CoversClass(X402FacilitatorClient::class)]
final class X402FacilitatorClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: array<string, mixed>, headers: list<string>, timeout: float}> */
    private array $requests = [];

    public function testVerifyRequestContainsExactPayloadAndPersistedRequirements(): void
    {
        $client = $this->client(new MockResponse('{"isValid":true,"payer":"0xPayer"}'));

        $result = $client->verify(X402Fixtures::config(), X402Fixtures::payload(), X402Fixtures::requirements());

        self::assertTrue($result->success);
        self::assertSame('0xPayer', $result->payer);
        self::assertNull($result->transactionHash);

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://facilitator.test/verify', $request['url']);
        self::assertSame(1, $request['body']['x402Version']);
        self::assertSame(X402Fixtures::payload()->toFacilitatorArray(), $request['body']['paymentPayload']);
        self::assertSame(X402Fixtures::requirements()->toArray(), $request['body']['paymentRequirements']);
        self::assertSame(10.0, $request['timeout']);
    }

    public function testSettleRequestIsIdenticalToVerifyRequestForTheSameSession(): void
    {
        $client = $this->client(
            new MockResponse('{"isValid":true}'),
            new MockResponse('{"success":true,"transaction":"0xhash"}'),
        );

        $client->verify(X402Fixtures::config(), X402Fixtures::payload(), X402Fixtures::requirements());
        $client->settle(X402Fixtures::config(), X402Fixtures::payload(), X402Fixtures::requirements());

        self::assertCount(2, $this->requests);
        self::assertSame('https://facilitator.test/verify', $this->requests[0]['url']);
        self::assertSame('https://facilitator.test/settle', $this->requests[1]['url']);
        self::assertSame($this->requests[0]['body'], $this->requests[1]['body']);
    }

    public function testApiKeyIsSentAsBearerTokenOnlyWhenConfigured(): void
    {
        $client = $this->client(new MockResponse('{"isValid":true}'), new MockResponse('{"isValid":true}'));

        $client->verify(X402Fixtures::config('secret-key'), X402Fixtures::payload(), X402Fixtures::requirements());
        $client->verify(X402Fixtures::config(), X402Fixtures::payload(), X402Fixtures::requirements());

        self::assertContains('authorization: bearer secret-key', $this->requests[0]['headers']);
        foreach ($this->requests[1]['headers'] as $header) {
            self::assertStringStartsNotWith('authorization:', $header);
        }
    }

    public function testVerifyRejectionIsMappedToFailureWithReason(): void
    {
        $client = $this->client(new MockResponse('{"isValid":false,"invalidReason":"insufficient_funds"}'));

        $result = $client->verify(X402Fixtures::config(), X402Fixtures::payload(), X402Fixtures::requirements());

        self::assertFalse($result->success);
        self::assertSame('insufficient_funds', $result->errorReason);
    }

    public function testSettleSuccessMapsTransactionHash(): void
    {
        $client = $this->client(new MockResponse('{"success":true,"payer":"0xPayer","transaction":"0xdeadbeef"}'));

        $result = $client->settle(X402Fixtures::config(), X402Fixtures::payload(), X402Fixtures::requirements());

        self::assertTrue($result->success);
        self::assertSame('0xdeadbeef', $result->transactionHash);
    }

    public function testSettleFailureNeverLooksLikeSuccess(): void
    {
        $client = $this->client(new MockResponse('{"success":false,"errorReason":"nonce_already_used"}'));

        $result = $client->settle(X402Fixtures::config(), X402Fixtures::payload(), X402Fixtures::requirements());

        self::assertFalse($result->success);
        self::assertSame('nonce_already_used', $result->errorReason);
    }

    public function testEmptySettleResponseIsFailure(): void
    {
        $client = $this->client(new MockResponse('{}'));

        $result = $client->settle(X402Fixtures::config(), X402Fixtures::payload(), X402Fixtures::requirements());

        self::assertFalse($result->success);
    }

    public function testTransportErrorRaisesFacilitatorUnavailable(): void
    {
        $client = $this->client(new MockResponse('', ['error' => 'connection refused']));

        try {
            $client->verify(X402Fixtures::config(), X402Fixtures::payload(), X402Fixtures::requirements());
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::FACILITATOR_UNAVAILABLE, $exception->getErrorCode());
        }
    }

    public function testMalformedJsonResponseRaisesFacilitatorUnavailable(): void
    {
        $client = $this->client(new MockResponse('this is not json'));

        try {
            $client->settle(X402Fixtures::config(), X402Fixtures::payload(), X402Fixtures::requirements());
            self::fail('expected X402Exception');
        } catch (X402Exception $exception) {
            self::assertSame(X402Exception::FACILITATOR_UNAVAILABLE, $exception->getErrorCode());
        }
    }

    private function client(MockResponse ...$responses): X402FacilitatorClient
    {
        $queue = array_values($responses);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue) {
            /** @var array<string, mixed> $body */
            $body = json_decode((string) $options['body'], true);
            /** @var list<string> $headers */
            $headers = $options['headers'];

            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => $body,
                'headers' => array_map(strtolower(...), $headers),
                'timeout' => (float) $options['timeout'],
            ];

            $response = array_shift($queue);
            \assert($response instanceof MockResponse);

            return $response;
        });

        return new X402FacilitatorClient($httpClient, new NullLogger(), new X402CdpJwtFactory());
    }
}

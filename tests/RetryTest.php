<?php

declare(strict_types=1);

namespace Hash\Tests;

use Hash\Exception\ApiException;
use Hash\Exception\ConflictException;
use Hash\Exception\ConnectionException;
use Hash\Exception\InvalidArgumentException;
use Hash\Exception\InvalidRequestException;
use Hash\Exception\RateLimitException;
use Hash\Exception\ValidationException;
use Hash\HttpClient\HttpClientInterface;
use Hash\HttpClient\Response;
use Hash\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RetryTest extends TestCase
{
    private const INV = 'inv_01kaf3q7m2x9c4v8b6n1d5r0tz';

    /** @return list<string> */
    private function keys(): array
    {
        return array_map(static fn (array $r): string => $r['headers']['idempotency-key'] ?? '', $this->server->requests());
    }

    public function testRetriesA429AfterRetryAfterWithTheSameKeyAndBody(): void
    {
        $this->server->enqueue(['status' => 429, 'body' => ['error' => 'rate_limited', 'retryAfter' => 7], 'headers' => ['Retry-After' => '7']]);
        $invoice = $this->client()->invoices->create(['price_usd' => '10.00']);
        self::assertStringStartsWith('inv_', $invoice->id);
        $requests = $this->server->requests();
        self::assertCount(2, $requests);
        self::assertSame([7.0], $this->sleeps);
        self::assertCount(1, array_unique($this->keys()));
        self::assertSame($requests[0]['body'], $requests[1]['body']);
    }

    public function testFallsBackToTheBodysRetryAfter(): void
    {
        $this->server->enqueue(['status' => 429, 'body' => ['error' => 'rate_limited', 'retryAfter' => 3]]);
        $this->client()->balances->retrieve();
        self::assertSame([3.0], $this->sleeps);
    }

    public function testDoesNotWaitMoreThanAMinute(): void
    {
        $this->server->enqueue(['status' => 429, 'body' => ['error' => 'rate_limited', 'retryAfter' => 120], 'headers' => ['Retry-After' => '120']]);
        try {
            $this->client()->balances->retrieve();
            self::fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(120, $e->getRetryAfter());
        }
        self::assertCount(1, $this->server->requests());
    }

    public function testRetries5xxWithBackoffAndKeepsTheKey(): void
    {
        $this->server->enqueue(
            ['status' => 503, 'body' => ['error' => 'php_jit_enabled', 'message' => 'Hash is temporarily unavailable.']],
            ['status' => 500, 'body' => ['message' => 'Server Error']],
        );
        $this->client()->invoices->cancel(self::INV);
        self::assertCount(3, $this->server->requests());
        self::assertCount(2, $this->sleeps);
        self::assertGreaterThanOrEqual(0.375, $this->sleeps[0]);
        self::assertLessThanOrEqual(0.625, $this->sleeps[0]);
        self::assertGreaterThanOrEqual(0.75, $this->sleeps[1]);
        self::assertLessThanOrEqual(1.25, $this->sleeps[1]);
        self::assertCount(1, array_unique($this->keys()));
    }

    public function testRetriesIdempotencyKeyInProgress(): void
    {
        $this->server->enqueue(['status' => 409, 'body' => ['error' => 'idempotency_key_in_progress']]);
        $this->client()->webhookEndpoints->enable('we_01kaf3t2b8k5r9m3x6c1v4n7qd');
        self::assertCount(2, $this->server->requests());
    }

    public function testNeverRetriesAnother409(): void
    {
        $this->server->enqueue(['status' => 409, 'body' => ['error' => 'duplicate_order_id', 'invoice_id' => self::INV]]);
        try {
            $this->client()->invoices->create(['price_usd' => '1.00', 'order_id' => '7731']);
            self::fail('expected ConflictException');
        } catch (ConflictException $e) {
            self::assertSame(self::INV, $e->getInvoiceId());
        }
        self::assertCount(1, $this->server->requests());
    }

    /** @return array<string, array{int, array<string, mixed>, class-string}> */
    public static function neverRetried(): array
    {
        return [
            'HTTP 400' => [400, ['error' => 'idempotency_key_required'], InvalidRequestException::class],
            '422 fields' => [422, ['message' => 'The price usd field is required.', 'errors' => ['price_usd' => ['The price usd field is required.']]], ValidationException::class],
            '422 reused' => [422, ['error' => 'idempotency_key_reused'], ValidationException::class],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param class-string         $class
     */
    #[DataProvider('neverRetried')]
    public function testNeverRetriesOther4xx(int $status, array $body, string $class): void
    {
        $this->server->enqueue(['status' => $status, 'body' => $body]);
        try {
            $this->client()->invoices->create(['price_usd' => '1.00']);
            self::fail('expected an exception');
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
        }
        self::assertCount(1, $this->server->requests());
        self::assertSame([], $this->sleeps);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $this->server->enqueue(...array_fill(0, 4, ['status' => 502, 'body' => ['message' => 'Bad gateway']]));
        try {
            $this->client(['max_retries' => 3])->balances->retrieve();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(502, $e->getHttpStatus());
        }
        self::assertCount(4, $this->server->requests());
    }

    public function testTakesMaxRetriesPerCall(): void
    {
        $this->server->enqueue(['status' => 500, 'body' => []]);
        $this->expectException(ApiException::class);
        try {
            $this->client()->balances->retrieve(['max_retries' => 0]);
        } finally {
            self::assertCount(1, $this->server->requests());
        }
    }

    public function testTimesOutASlowAnswer(): void
    {
        $this->server->enqueue(['status' => 200, 'body' => [], 'delay' => 1.0]);
        try {
            $this->client(['timeout' => 0.2, 'max_retries' => 0])->balances->retrieve();
            self::fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertTrue($e->isTimeout());
            self::assertNull($e->getHttpStatus());
        }
        usleep(900_000); // let the server finish the slow answer
    }

    public function testRetriesAConnectionFailureThenSucceeds(): void
    {
        $transport = new ScriptedTransport([new ConnectionException('reset'), new ConnectionException('reset'), new Response(200, [], '{"ipv4":[],"ipv6":[]}')]);
        $ips = $this->client(['http_client' => $transport])->meta->webhookIps();
        self::assertSame([], $ips->ipv4);
        self::assertSame(3, $transport->calls);
        self::assertCount(2, $this->sleeps);
    }

    public function testThrowsConnectionExceptionWhenNothingAnswers(): void
    {
        $transport = new ScriptedTransport(array_fill(0, 3, new ConnectionException('refused')));
        $this->expectException(ConnectionException::class);
        $this->client(['http_client' => $transport])->invoices->create(['price_usd' => '1.00']);
    }

    public function testReachesNothingOnAClosedPort(): void
    {
        $this->expectException(ConnectionException::class);
        $this->client(['base_url' => 'http://127.0.0.1:9/api/v1', 'max_retries' => 0])->balances->retrieve();
    }

    public function testUsesTheCallersIdempotencyKey(): void
    {
        $this->client()->invoices->create(['price_usd' => '48.50', 'order_id' => '7731'], ['idempotency_key' => 'order-7731']);
        self::assertSame(['order-7731'], $this->keys());
    }

    public function testMakesANewKeyForEachCall(): void
    {
        $hash = $this->client();
        $hash->invoices->cancel(self::INV);
        $hash->invoices->cancel(self::INV);
        self::assertCount(2, array_unique($this->keys()));
    }

    /** @return array<string, array{string}> */
    public static function badKeys(): array
    {
        return ['empty' => [''], 'blank' => ['   '], '256 bytes' => [str_repeat('x', 256)], '256 bytes of é' => [str_repeat('é', 128)]];
    }

    #[DataProvider('badKeys')]
    public function testRefusesAnInvalidKeyBeforeSending(string $key): void
    {
        try {
            $this->client()->invoices->create(['price_usd' => '1.00'], ['idempotency_key' => $key]);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], $this->server->requests());
    }

    public function testAcceptsAKeyOf255Bytes(): void
    {
        $this->client()->invoices->create(['price_usd' => '1.00'], ['idempotency_key' => str_repeat('k', 255)]);
        self::assertCount(1, $this->server->requests());
    }
}

/** A transport that answers from a script: a Response, or a ConnectionException to throw. */
final class ScriptedTransport implements HttpClientInterface
{
    public int $calls = 0;

    /** @param list<Response|ConnectionException> $script */
    public function __construct(private array $script)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response
    {
        ++$this->calls;
        $next = array_shift($this->script) ?? throw new \LogicException('The script ran out.');
        if ($next instanceof ConnectionException) {
            throw $next;
        }

        return $next;
    }
}

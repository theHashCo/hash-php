<?php

declare(strict_types=1);

namespace Hash\Tests;

use Hash\Exception\ApiException;
use Hash\Exception\AuthenticationException;
use Hash\Exception\ConflictException;
use Hash\Exception\HashException;
use Hash\Exception\InvalidRequestException;
use Hash\Exception\NotFoundException;
use Hash\Exception\PermissionException;
use Hash\Exception\RateLimitException;
use Hash\Exception\UnexpectedValueException;
use Hash\Exception\ValidationException;
use Hash\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ErrorTest extends TestCase
{
    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function errorFor(int $status, array $body, array $headers = []): HashException
    {
        $this->server->enqueue(['status' => $status, 'body' => $body, 'headers' => $headers]);
        try {
            $this->client(['max_retries' => 0])->invoices->retrieve('inv_01kaf3q7m2x9c4v8b6n1d5r0tz');
        } catch (HashException $e) {
            return $e;
        }
        self::fail('expected an exception');
    }

    /** @return array<string, array{int, array<string, mixed>, class-string<HashException>}> */
    public static function statuses(): array
    {
        return [
            'HTTP 400' => [400, ['error' => 'idempotency_key_required'], InvalidRequestException::class],
            'HTTP 401' => [401, ['error' => 'invalid_api_key'], AuthenticationException::class],
            'HTTP 403' => [403, ['error' => 'tenant_suspended'], PermissionException::class],
            'HTTP 404' => [404, ['error' => 'not_found'], NotFoundException::class],
            'HTTP 405' => [405, ['error' => 'method_not_allowed', 'message' => 'The POST method is not allowed here. Allowed: GET, HEAD.'], InvalidRequestException::class],
            'HTTP 409' => [409, ['error' => 'invalid_state'], ConflictException::class],
            'HTTP 422' => [422, ['error' => 'invalid_cursor', 'message' => 'Start again.'], ValidationException::class],
            'HTTP 429' => [429, ['error' => 'rate_limited', 'retryAfter' => 30], RateLimitException::class],
            'HTTP 500' => [500, ['message' => 'Server Error'], ApiException::class],
            'HTTP 503' => [503, ['error' => 'php_jit_enabled', 'message' => 'Hash is temporarily unavailable.'], ApiException::class],
        ];
    }

    /**
     * @param array<string, mixed>          $body
     * @param class-string<HashException> $class
     */
    #[DataProvider('statuses')]
    public function testMapsEachStatusToItsClass(int $status, array $body, string $class): void
    {
        $e = $this->errorFor($status, $body);
        self::assertInstanceOf($class, $e);
        self::assertSame($status, $e->getHttpStatus());
        self::assertSame($status, $e->getCode());
        self::assertSame($body['error'] ?? null, $e->getErrorCode());
        self::assertSame($body, $e->getJsonBody());
        self::assertSame(json_encode($body, JSON_UNESCAPED_SLASHES), $e->getRawBody());
        self::assertStringContainsString("Hash answered $status", $e->getMessage());
    }

    public function testCarriesFieldErrors(): void
    {
        $errors = [
            'price_usd' => ['The price usd field is required.'],
            'metadata.cart_id' => ['Metadata values must be a string, a number or a boolean (no nested objects, arrays or null).'],
        ];
        $e = $this->errorFor(422, ['message' => 'The price usd field is required. (and 1 more error)', 'errors' => $errors]);
        self::assertInstanceOf(ValidationException::class, $e);
        self::assertNull($e->getErrorCode());
        self::assertSame($errors, $e->getErrors());
    }

    public function testCarriesTheRequiredScope(): void
    {
        $e = $this->errorFor(403, ['error' => 'insufficient_scope', 'required' => 'invoices:write']);
        self::assertInstanceOf(PermissionException::class, $e);
        self::assertSame('invoices:write', $e->getRequiredScope());
    }

    public function testCarriesConflictDetails(): void
    {
        $e = $this->errorFor(409, ['error' => 'duplicate_order_id', 'invoice_id' => 'inv_01kae2e0000000000000000042']);
        self::assertInstanceOf(ConflictException::class, $e);
        self::assertSame('inv_01kae2e0000000000000000042', $e->getInvoiceId());

        $resource = ['id' => 'we_01kaf3t2b8k5r9m3x6c1v4n7qd', 'url' => 'https://x.example', 'mode' => 'live', 'events' => ['invoice.paid'], 'enabled' => true, 'created_at' => '2026-11-20T10:00:00Z'];
        $e = $this->errorFor(409, ['error' => 'secret_shown_once', 'resource' => $resource]);
        self::assertInstanceOf(ConflictException::class, $e);
        self::assertSame($resource, $e->getResource());

        $e = $this->errorFor(409, ['error' => 'preview_stale', 'preview' => ['amount' => '1.0']]);
        self::assertInstanceOf(ConflictException::class, $e);
        self::assertSame(['amount' => '1.0'], $e->getPreview());
    }

    public function testReadsRetryAfterFromTheHeaderFirst(): void
    {
        $e = $this->errorFor(429, ['error' => 'rate_limited', 'retryAfter' => 30], ['Retry-After' => '12']);
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertSame(12, $e->getRetryAfter());
    }

    public function testKeepsARequestIdWhenOneIsSent(): void
    {
        self::assertSame('req_123', $this->errorFor(404, ['error' => 'not_found'], ['X-Request-Id' => 'req_123'])->getRequestId());
        self::assertNull($this->errorFor(404, ['error' => 'not_found'])->getRequestId());
    }

    public function testHandlesBodiesWithoutACodeOrNotJson(): void
    {
        self::assertNull($this->errorFor(404, ['message' => 'Not found.'])->getErrorCode());

        $this->server->enqueue(['status' => 502, 'raw' => '<html>Bad gateway</html>']);
        try {
            $this->client(['max_retries' => 0])->balances->retrieve();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertNull($e->getJsonBody());
            self::assertSame('<html>Bad gateway</html>', $e->getRawBody());
        }
    }

    public function testThrowsForA2xxBodyThatIsNotJson(): void
    {
        $this->server->enqueue(['status' => 200, 'raw' => 'oops']);
        $this->expectException(UnexpectedValueException::class);
        $this->client()->balances->retrieve();
    }

    public function testThrowsForAResponseMissingARequiredField(): void
    {
        $invoice = self::example('retrieveInvoice');
        unset($invoice['pay_url']);
        $this->server->enqueue(['status' => 200, 'body' => $invoice]);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invoice: Hash sent no "pay_url".');
        $this->client()->invoices->retrieve('inv_01kaf3q7m2x9c4v8b6n1d5r0tz');
    }

    public function testKeepsFieldsThisVersionDoesNotKnow(): void
    {
        $invoice = self::example('retrieveInvoice');
        $invoice['brand_new_field'] = 'x';
        $this->server->enqueue(['status' => 200, 'body' => $invoice]);
        self::assertSame('x', $this->client()->invoices->retrieve('inv_01kaf3q7m2x9c4v8b6n1d5r0tz')->toArray()['brand_new_field']);
    }
}

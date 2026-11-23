<?php

declare(strict_types=1);

namespace Hash\Tests;

use Hash\Tests\Support\TestCase;

/** The mock must catch requests the spec refuses, or the operation tests prove nothing. */
final class MockServerTest extends TestCase
{
    public function testRecordsRequestsTheSpecRefuses(): void
    {
        // Deliberately wrong: PHPStan catches it too, which is the point of the generated array shapes.
        $this->client(['max_retries' => 0])->invoices->create(['price_usd' => 48.5, 'allowed_assets' => ['doge']]); // @phpstan-ignore argument.type
        $this->client()->invoices->list(['limit' => 500, 'colour' => 'red']);
        $problems = $this->server->problems();
        $this->server->reset();

        $text = implode("\n", $problems);
        self::assertStringContainsString('POST /invoices: body', $text);
        self::assertStringContainsString('price_usd', $text);
        self::assertStringContainsString('allowed_assets', $text);
        self::assertStringContainsString('query limit=500', $text);
        self::assertStringContainsString('unknown query parameter colour', $text);
    }

    public function testRecordsAMissingKeyAndIdempotencyKey(): void
    {
        $ch = curl_init($this->server->url . '/invoices');
        self::assertNotFalse($ch);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"price_usd":"1.00"}', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        curl_exec($ch);
        curl_close($ch);
        $problems = implode("\n", $this->server->problems());
        $this->server->reset();
        self::assertStringContainsString('Authorization is not a bearer secret key', $problems);
        self::assertStringContainsString('missing header Idempotency-Key', $problems);
        self::assertStringContainsString('User-Agent', $problems);
    }
}

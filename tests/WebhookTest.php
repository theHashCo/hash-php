<?php

declare(strict_types=1);

namespace Hash\Tests;

use Hash\Enum\EventType;
use Hash\Event;
use Hash\Exception\SignatureVerificationException;
use Hash\Tests\Support\Spec;
use Hash\Webhook;
use Hash\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    // The test vector published on https://docs.hash.bh/webhooks/verify
    private const SECRET = 'whsec_Jx7kP2mQ9vR4tY8wZ1aB3cD5eF6gH0iL2nO4pS6uV8x';
    private const T = 1795108117;
    private const BODY = '{"id":"evt_01kae2e0000000000000000103","type":"invoice.paid","created_at":"2026-11-20T10:04:12Z","data":{"invoice":{"id":"inv_01kae2e0000000000000000042","status":"paid"},"details":{}}}';
    private const HEADER = 't=1795108117,v1=50bbcde9fee24500787a4d6e277be87cec83e6115857d6a315621b83e34582d9';

    /**
     * @param array<string, mixed>|string $headers
     * @param string|list<string>         $secret
     */
    private static function verify(string $body, array|string $headers, string|array $secret = self::SECRET, int $now = self::T): Event
    {
        return (new Webhooks())->constructEvent($body, $headers, $secret, 300, $now);
    }

    public function testAcceptsTheDocsTestVector(): void
    {
        $event = self::verify(self::BODY, self::HEADER);
        self::assertSame('evt_01kae2e0000000000000000103', $event->id);
        self::assertSame(EventType::INVOICE_PAID, $event->type);
        self::assertSame('2026-11-20T10:04:12Z', $event->created_at);
        self::assertTrue($event->isInvoiceEvent());
        self::assertSame('inv_01kae2e0000000000000000042', $event->data['invoice']['id']);
        self::assertSame(json_decode(self::BODY, true), $event->toArray());
    }

    public function testWorksStaticallyAndWithTheRealClock(): void
    {
        $event = Webhook::constructEvent(self::BODY, ['Hash-Signature' => self::HEADER], self::SECRET, PHP_INT_MAX);
        self::assertSame('invoice.paid', $event->type);
        $this->expectException(SignatureVerificationException::class);
        // t is 2026-11-20T10:28:37Z: with the real clock and the default 5 minutes, it is too old.
        Webhook::constructEvent(self::BODY, self::HEADER, self::SECRET);
    }

    /** @return array<string, array{string, string, string}> */
    public static function tampered(): array
    {
        return [
            'a changed body byte' => [str_replace('"paid"}', '"paiD"}', self::BODY), self::HEADER, self::SECRET],
            'extra whitespace in the body' => [self::BODY . "\n", self::HEADER, self::SECRET],
            'a changed secret' => [self::BODY, self::HEADER, substr(self::SECRET, 0, -1) . 'y'],
            'the secret without whsec_' => [self::BODY, self::HEADER, substr(self::SECRET, 6)],
            'a changed timestamp' => [self::BODY, str_replace('t=1795108117', 't=1795108118', self::HEADER), self::SECRET],
            'a changed signature' => [self::BODY, substr(self::HEADER, 0, -1) . '8', self::SECRET],
        ];
    }

    #[DataProvider('tampered')]
    public function testRejectsTampering(string $body, string $header, string $secret): void
    {
        $this->expectException(SignatureVerificationException::class);
        self::verify($body, $header, $secret);
    }

    public function testRejectsATimestampMoreThanFiveMinutesAway(): void
    {
        self::verify(self::BODY, self::HEADER, self::SECRET, self::T + 300);
        self::verify(self::BODY, self::HEADER, self::SECRET, self::T - 300);
        foreach ([self::T + 301, self::T - 301] as $now) {
            try {
                self::verify(self::BODY, self::HEADER, self::SECRET, $now);
                self::fail('expected SignatureVerificationException');
            } catch (SignatureVerificationException $e) {
                self::assertStringContainsString('more than 300 seconds', $e->getMessage());
            }
        }
    }

    public function testTakesACustomTolerance(): void
    {
        $w = new Webhooks();
        self::assertSame('invoice.paid', $w->constructEvent(self::BODY, self::HEADER, self::SECRET, 3600, self::T + 3000)->type);
        $this->expectException(SignatureVerificationException::class);
        $w->constructEvent(self::BODY, self::HEADER, self::SECRET, 10, self::T + 11);
    }

    public function testAcceptsEitherSignatureDuringARotation(): void
    {
        $sig = substr(self::HEADER, strpos(self::HEADER, 'v1=') + 3);
        self::verify(self::BODY, 't=' . self::T . ',v1=' . str_repeat('0', 64) . ",v1=$sig");
        self::verify(self::BODY, 't=' . self::T . ",v1=$sig,v1=" . str_repeat('f', 64));
        $this->addToAssertionCount(2);
    }

    public function testAcceptsAnyOfSeveralSecrets(): void
    {
        self::verify(self::BODY, self::HEADER, ['whsec_other', self::SECRET]);
        $this->expectException(SignatureVerificationException::class);
        self::verify(self::BODY, self::HEADER, ['whsec_other']);
    }

    public function testFindsTheHeaderInEveryCommonShape(): void
    {
        foreach ([
            ['Hash-Signature' => self::HEADER], // getallheaders()
            ['hash-signature' => [self::HEADER]], // PSR-7 getHeaders(), Laravel
            ['HTTP_HASH_SIGNATURE' => self::HEADER, 'REQUEST_METHOD' => 'POST'], // $_SERVER
            self::HEADER, // the value itself
        ] as $headers) {
            self::assertSame('invoice.paid', self::verify(self::BODY, $headers)->type);
        }
    }

    /** @return array<string, array{array<string, string>|string}> */
    public static function badHeaders(): array
    {
        $sig = '50bbcde9fee24500787a4d6e277be87cec83e6115857d6a315621b83e34582d9';

        return [
            'missing' => [[]],
            'empty' => [''],
            'without t' => ["v1=$sig"],
            'without v1' => ['t=1795108117'],
            'with a non-numeric t' => ["t=abc,v1=$sig"],
            'with another scheme only' => ["t=1795108117,v0=$sig"],
        ];
    }

    /** @param array<string, string>|string $header */
    #[DataProvider('badHeaders')]
    public function testRejectsABadHeader(array|string $header): void
    {
        $this->expectException(SignatureVerificationException::class);
        self::verify(self::BODY, $header);
    }

    public function testRefusesAnEmptySecret(): void
    {
        $this->expectException(SignatureVerificationException::class);
        self::verify(self::BODY, self::HEADER, '');
    }

    public function testGeneratesTheSameHeaderAsHash(): void
    {
        self::assertSame(self::HEADER, (new Webhooks())->generateTestHeader(self::BODY, self::SECRET, self::T));
    }

    public function testReadsEveryExampleEventOfTheSpecTyped(): void
    {
        $secret = 'whsec_' . str_repeat('A', 43);
        $w = new Webhooks();
        $seen = 0;
        foreach (Spec::get()['webhooks'] as $type => $item) {
            $content = $item['post']['requestBody']['content']['application/json'];
            if (!\array_key_exists('example', $content)) {
                continue; // the spec has examples for some events only
            }
            $body = (string) json_encode($content['example'], JSON_UNESCAPED_SLASHES);
            $event = $w->constructEvent($body, $w->generateTestHeader($body, $secret, 1795176252), $secret, 300, 1795176252);
            self::assertSame($type, $event->type);
            if ($event->isInvoiceEvent()) {
                self::assertStringStartsWith('inv_', $event->invoice()->id);
                $event->invoiceDetails();
            } elseif ($event->isWithdrawalEvent()) {
                self::assertSame('withdrawal', $event->withdrawal()->object);
            } elseif ($event->isTest()) {
                self::assertStringStartsWith('we_', $event->test()->endpoint_id);
            } elseif ($type === EventType::WALLET_DEPOSIT_CONFIRMED) {
                self::assertNotSame('', $event->wallet()->address);
                self::assertNotSame('', $event->walletDepositDetails()->tx_hash);
            }
            ++$seen;
        }
        self::assertGreaterThanOrEqual(5, $seen);
    }

    public function testListsEveryEventType(): void
    {
        self::assertSame(array_keys(Spec::get()['webhooks']), EventType::ALL);
        self::assertCount(26, EventType::ALL);
    }
}

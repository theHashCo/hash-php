<?php

declare(strict_types=1);

namespace Hash\Tests;

use Hash\HashClient;
use Hash\Model\AbstractModel;
use Hash\Page;
use Hash\Tests\Support\Spec;
use Hash\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class OperationsTest extends TestCase
{
    private const INV = 'inv_01kaf3q7m2x9c4v8b6n1d5r0tz';
    private const WE = 'we_01kaf3t2b8k5r9m3x6c1v4n7qd';
    private const WHD = 'whd_01kaf3z0q5w8e2r6t9y3u7i1ob';

    /** @return array<string, array{0: callable(HashClient): mixed, 1: string, 2: string}> one call per operation of the spec */
    public static function calls(): array
    {
        $inv = self::INV;
        $we = self::WE;
        $whd = self::WHD;

        return [
            'listInvoices' => [static fn (HashClient $h) => $h->invoices->list(['status' => ['paid', 'paid_late'], 'order_id' => '7731', 'asset' => 'usdt_trc20', 'created' => ['gte' => '2026-11-01', 'lte' => '2026-11-20T23:59:59Z'], 'limit' => 20]), 'GET', '/invoices'],
            'createInvoice' => [static fn (HashClient $h) => $h->invoices->create([
                'price_usd' => '48.50',
                'order_id' => '7731',
                'allowed_assets' => ['usdt_trc20', 'btc'],
                'fee_payer' => 'merchant',
                'success_url' => 'https://northwind.example/orders/7731',
                'cancel_url' => 'https://northwind.example/cart',
                'customer_email' => 'layla.haddad@example.com',
                'description' => '2 x Ethiopian Yirgacheffe, 250 g',
                'metadata' => ['customer_id' => 'cus_4410', 'gift' => true, 'qty' => 2],
            ]), 'POST', '/invoices'],
            'retrieveInvoice' => [static fn (HashClient $h) => $h->invoices->retrieve($inv), 'GET', "/invoices/$inv"],
            'listBalances' => [static fn (HashClient $h) => $h->balances->retrieve(), 'GET', '/balances'],
            'acceptPayment' => [static fn (HashClient $h) => $h->invoices->accept($inv, ['note' => 'Customer emailed us']), 'POST', "/invoices/$inv/accept"],
            'cancelInvoice' => [static fn (HashClient $h) => $h->invoices->cancel($inv), 'POST', "/invoices/$inv/cancel"],
            'creditWrongNetwork' => [static fn (HashClient $h) => $h->invoices->creditWrongNetwork($inv), 'POST', "/invoices/$inv/credit-wrong-network"],
            'listWebhookEndpoints' => [static fn (HashClient $h) => $h->webhookEndpoints->list(['limit' => 5]), 'GET', '/webhook-endpoints'],
            'createWebhookEndpoint' => [static fn (HashClient $h) => $h->webhookEndpoints->create(['url' => 'https://shop.example/hash/webhooks', 'events' => ['invoice.paid', 'invoice.expired']]), 'POST', '/webhook-endpoints'],
            'retrieveWebhookEndpoint' => [static fn (HashClient $h) => $h->webhookEndpoints->retrieve($we), 'GET', "/webhook-endpoints/$we"],
            'updateWebhookEndpoint' => [static fn (HashClient $h) => $h->webhookEndpoints->update($we, ['url' => 'https://new.example/hash/webhooks']), 'PATCH', "/webhook-endpoints/$we"],
            'enableWebhookEndpoint' => [static fn (HashClient $h) => $h->webhookEndpoints->enable($we), 'POST', "/webhook-endpoints/$we/enable"],
            'testWebhookEndpoint' => [static fn (HashClient $h) => $h->webhookEndpoints->test($we), 'POST', "/webhook-endpoints/$we/test"],
            'listWebhookDeliveries' => [static fn (HashClient $h) => $h->webhookDeliveries->list(['endpoint_id' => $we, 'event_type' => 'invoice.paid', 'status' => ['failed', 'pending'], 'created' => ['gte' => '2026-11-01']]), 'GET', '/webhook-deliveries'],
            'resendWebhookDelivery' => [static fn (HashClient $h) => $h->webhookDeliveries->resend($whd), 'POST', "/webhook-deliveries/$whd/resend"],
            'listWebhookSourceIps' => [static fn (HashClient $h) => $h->meta->webhookIps(), 'GET', '/meta/webhook-ips'],
        ];
    }

    public function testCoversEveryOperationOfTheSpec(): void
    {
        $ids = array_column(Spec::operations(), 'id');
        sort($ids);
        $mine = array_keys(self::calls());
        sort($mine);
        self::assertSame($ids, $mine);
        self::assertCount(16, $ids);
    }

    /** @param callable(HashClient): mixed $call */
    #[DataProvider('calls')]
    public function testSendsARequestTheSpecAcceptsAndReturnsTheExample(callable $call, string $method, string $path): void
    {
        $result = $call($this->client());
        $requests = $this->server->requests();
        self::assertCount(1, $requests);
        $req = $requests[0];
        self::assertSame($method, $req['method']);
        self::assertSame($path, $req['path']);

        $operationId = (string) $req['operation'];
        $example = self::example($operationId);
        if ($result instanceof Page) {
            self::assertSame($example['data'], array_map(static fn (AbstractModel $m): array => $m->toArray(), $result->data));
            self::assertSame($example['has_more'], $result->has_more);
            self::assertSame($example['next_cursor'], $result->next_cursor);
        } else {
            self::assertInstanceOf(AbstractModel::class, $result);
            self::assertSame($example, $result->toArray());
        }

        if ($method === 'POST' || $method === 'PATCH') {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $req['headers']['idempotency-key'] ?? '');
        } else {
            self::assertArrayNotHasKey('idempotency-key', $req['headers']);
        }
    }

    public function testTypedFieldsAreReadable(): void
    {
        $invoice = $this->client()->invoices->retrieve(self::INV);
        self::assertSame(self::INV, $invoice->id);
        self::assertSame('48.50', $invoice->price_usd);
        self::assertSame('https://pay.hash.bh/i/' . self::INV, $invoice->pay_url);
        self::assertContainsOnlyInstancesOf(\Hash\Model\InvoicePayment::class, $invoice->payments);
        self::assertNull($invoice->callback_url);

        $endpoint = $this->client()->webhookEndpoints->create(['url' => 'https://shop.example/hash/webhooks', 'events' => ['invoice.paid']]);
        self::assertStringStartsWith('whsec_', $endpoint->secret);
    }

    public function testSerializesListFiltersAsTheSpecDescribes(): void
    {
        $this->client()->invoices->list(['status' => ['paid', 'paid_late'], 'created' => ['gte' => '2026-11-01', 'lte' => '2026-11-20T23:59:59Z'], 'limit' => 20]);
        self::assertSame('status=paid%2Cpaid_late&created%5Bgte%5D=2026-11-01&created%5Blte%5D=2026-11-20T23%3A59%3A59Z&limit=20', str_replace(',', '%2C', $this->server->requests()[0]['query']));
    }

    public function testSendsNoBodyToOperationsThatTakeNone(): void
    {
        $this->client()->invoices->cancel(self::INV);
        $this->client()->invoices->accept(self::INV);
        foreach ($this->server->requests() as $r) {
            self::assertSame('', $r['body']);
            self::assertArrayNotHasKey('content-type', $r['headers']);
        }
    }

    public function testSendsMoneyAsAString(): void
    {
        $this->client()->invoices->create(['price_usd' => '48.50']);
        self::assertSame('{"price_usd":"48.50"}', $this->server->requests()[0]['body']);
    }

    public function testSendsThePublicIpListWithoutTheKey(): void
    {
        $this->client()->meta->webhookIps();
        self::assertArrayNotHasKey('authorization', $this->server->requests()[0]['headers']);
    }

    public function testEncodesPathParameters(): void
    {
        $this->server->enqueue(['status' => 404, 'body' => ['error' => 'not_found']]);
        try {
            $this->client()->invoices->retrieve('inv/../x');
            self::fail('expected NotFoundException');
        } catch (\Hash\Exception\NotFoundException) {
        }
        self::assertSame('/api/v1/invoices/inv%2F..%2Fx', $this->server->requests()[0]['uri']);
        $this->server->reset(); // the path is not in the spec, on purpose
    }

    public function testRefusesAnEmptyId(): void
    {
        $this->expectException(\Hash\Exception\InvalidArgumentException::class);
        $this->client()->invoices->retrieve('');
    }
}

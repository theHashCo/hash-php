<?php

declare(strict_types=1);

namespace Hash\Tests;

use Hash\Exception\ValidationException;
use Hash\Model\Invoice;
use Hash\Tests\Support\TestCase;

final class PaginationTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function inv(int $n): array
    {
        $invoice = self::example('retrieveInvoice');
        $invoice['id'] = 'inv_' . str_pad((string) $n, 26, '0', STR_PAD_LEFT);

        return $invoice;
    }

    /**
     * @param list<int> $ids
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function page(array $ids, ?string $next): array
    {
        return ['status' => 200, 'body' => ['object' => 'list', 'data' => array_map(self::inv(...), $ids), 'has_more' => $next !== null, 'next_cursor' => $next]];
    }

    /**
     * @param iterable<Invoice> $invoices
     *
     * @return list<string>
     */
    private static function ids(iterable $invoices): array
    {
        $out = [];
        foreach ($invoices as $i) {
            $out[] = $i->id;
        }

        return $out;
    }

    public function testReturnsTheFirstPage(): void
    {
        $this->server->enqueue(self::page([1, 2], 'c2'));
        $page = $this->client()->invoices->list(['limit' => 2]);
        self::assertSame([self::inv(1)['id'], self::inv(2)['id']], self::ids($page));
        self::assertCount(2, $page);
        self::assertTrue($page->has_more);
        self::assertSame('c2', $page->next_cursor);
        self::assertTrue($page->hasNextPage());
        self::assertSame('list', $page->object);
    }

    public function testWalksEveryObjectSendingTheSameFiltersWithEachCursor(): void
    {
        $this->server->enqueue(self::page([1, 2], 'c2'), self::page([3, 4], 'c3'), self::page([5], null));
        $ids = self::ids($this->client()->invoices->list(['status' => ['paid'], 'limit' => 2])->autoPagingIterator());
        self::assertSame(array_map(static fn (int $n): string => self::inv($n)['id'], [1, 2, 3, 4, 5]), $ids);
        self::assertSame(
            ['/api/v1/invoices?status=paid&limit=2', '/api/v1/invoices?status=paid&limit=2&cursor=c2', '/api/v1/invoices?status=paid&limit=2&cursor=c3'],
            array_column($this->server->requests(), 'uri'),
        );
    }

    public function testNextPageFollowsTheCursor(): void
    {
        $this->server->enqueue(self::page([1], 'abc'), self::page([2], null));
        $first = $this->client()->invoices->list(['limit' => 1]);
        $second = $first->nextPage();
        self::assertSame(self::inv(2)['id'], $second->data[0]->id);
        self::assertFalse($second->hasNextPage());
        $this->expectException(\LogicException::class);
        $second->nextPage();
    }

    public function testStopsWhenThereIsNoCursor(): void
    {
        $this->server->enqueue(['status' => 200, 'body' => ['object' => 'list', 'data' => [self::inv(1)], 'has_more' => true, 'next_cursor' => null]]);
        self::assertCount(1, iterator_to_array($this->client()->invoices->list()->autoPagingIterator(), false));
    }

    public function testPagesDeliveries(): void
    {
        $delivery = ['id' => 'whd_01kaf3z0q5w8e2r6t9y3u7i1ob', 'event_id' => 'evt_01kaf3z9w4e7r1t5y8u2i6o3pa', 'endpoint_id' => 'we_01kaf3t2b8k5r9m3x6c1v4n7qd', 'status' => 'failed', 'attempt' => 11, 'response_status' => 502, 'next_attempt_at' => null, 'created_at' => '2026-11-20T10:04:12Z'];
        $this->server->enqueue(
            ['status' => 200, 'body' => ['object' => 'list', 'data' => [$delivery], 'has_more' => true, 'next_cursor' => 'n']],
            ['status' => 200, 'body' => ['object' => 'list', 'data' => [$delivery], 'has_more' => false, 'next_cursor' => null]],
        );
        $all = iterator_to_array($this->client()->webhookDeliveries->list(['status' => ['failed']])->autoPagingIterator(), false);
        self::assertCount(2, $all);
        self::assertSame(502, $all[0]->response_status);
        self::assertSame('/api/v1/webhook-deliveries?status=failed&cursor=n', $this->server->requests()[1]['uri']);
    }

    public function testSurfacesInvalidCursor(): void
    {
        $this->server->enqueue(['status' => 422, 'body' => ['error' => 'invalid_cursor', 'message' => 'Start again from the first page.']]);
        try {
            $this->client()->invoices->list(['cursor' => 'bogus']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('invalid_cursor', $e->getErrorCode());
        }
    }
}

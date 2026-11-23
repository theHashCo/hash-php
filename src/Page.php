<?php

declare(strict_types=1);

namespace Hash;

use ArrayIterator;
use Closure;
use Countable;
use Generator;
use Hash\Exception\HashException;
use IteratorAggregate;
use LogicException;

/**
 * One page of a list, newest first.
 *
 * Iterating a Page walks this page only. autoPagingIterator() walks every object across every
 * page, fetching the next page with the same filters as it goes:
 *
 *     foreach ($hash->invoices->list(['status' => ['paid']])->autoPagingIterator() as $invoice) { ... }
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final class Page implements IteratorAggregate, Countable
{
    public readonly string $object;

    /**
     * @param list<T>                  $data
     * @param Closure(?string): Page<T> $fetch
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $has_more,
        public readonly ?string $next_cursor,
        private readonly Closure $fetch,
    ) {
        $this->object = 'list';
    }

    /** True when another page follows. */
    public function hasNextPage(): bool
    {
        return $this->has_more && $this->next_cursor !== null;
    }

    /**
     * Fetches the next page with the same filters.
     *
     * @return Page<T>
     *
     * @throws HashException
     */
    public function nextPage(): self
    {
        if (!$this->hasNextPage()) {
            throw new LogicException('This is the last page: check hasNextPage() first.');
        }

        return ($this->fetch)($this->next_cursor);
    }

    /**
     * Every object across this page and the ones after it.
     *
     * @return Generator<int, T>
     *
     * @throws HashException
     */
    public function autoPagingIterator(): Generator
    {
        $page = $this;
        while (true) {
            foreach ($page->data as $item) {
                yield $item;
            }
            if (!$page->hasNextPage()) {
                return;
            }
            $page = $page->nextPage();
        }
    }

    /** @return ArrayIterator<int, T> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->data);
    }

    public function count(): int
    {
        return \count($this->data);
    }
}

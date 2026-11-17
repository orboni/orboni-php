<?php

declare(strict_types=1);

namespace Orboni;

/**
 * One page of a list, as the API answers it: `object`, `data`, `has_more`,
 * `url`. foreach walks this page's `data`; autoPagingIterator() walks every
 * object across the pages, asking for the next page as it goes.
 *
 *     $page = $orboni->refunds->list(['limit' => 10]);
 *     foreach ($orboni->refunds->list(['limit' => 100])->autoPagingIterator() as $refund) { … }
 */
class Collection extends OrboniObject implements \Countable
{
    /** @var (\Closure(array<string, mixed>): OrboniObject)|null */
    private ?\Closure $fetchPage = null;

    /** @var array<string, mixed> */
    private array $params = [];

    /**
     * @internal How the next pages are asked for: the call's parameters and a fetcher of one page.
     *
     * @param  \Closure(array<string, mixed>): OrboniObject  $fetchPage
     * @param  array<string, mixed>  $params
     */
    public function withPaging(\Closure $fetchPage, array $params): static
    {
        $this->fetchPage = $fetchPage;
        $this->params = $params;

        return $this;
    }

    /**
     * This page's objects.
     *
     * @return \ArrayIterator<int, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator(self::items($this));
    }

    /** How many objects this page holds. */
    public function count(): int
    {
        return count(self::items($this));
    }

    /**
     * Every object, page after page; backwards from `ending_before` when the
     * list was asked with it, each page's objects then oldest first.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(): \Generator
    {
        $backwards = is_string($this->params['ending_before'] ?? null);
        $page = $this;
        while (true) {
            $items = self::items($page);
            foreach ($backwards ? array_reverse($items) : $items as $item) {
                yield $item;
            }
            $edge = $backwards ? ($items[0] ?? null) : ($items[count($items) - 1] ?? null);
            $id = $edge instanceof OrboniObject ? $edge['id'] : null;
            if ($page['has_more'] !== true || ! is_string($id) || $this->fetchPage === null) {
                return;
            }
            $params = $this->params;
            unset($params['starting_after'], $params['ending_before']);
            $params[$backwards ? 'ending_before' : 'starting_after'] = $id;
            $page = ($this->fetchPage)($params);
        }
    }

    /**
     * Up to $limit objects across the pages, in one array.
     *
     * @return list<mixed>
     */
    public function autoPagingToArray(int $limit): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('autoPagingToArray needs a limit between 1 and 10000.');
        }
        $items = [];
        foreach ($this->autoPagingIterator() as $item) {
            $items[] = $item;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /** @return list<mixed> */
    private static function items(OrboniObject $page): array
    {
        $data = $page['data'];

        return is_array($data) ? array_values($data) : [];
    }
}

<?php

declare(strict_types=1);

namespace Orboni\Service;

use Orboni\Collection;
use Orboni\OrboniObject;

/**
 * `/v1/refunds`: a record of money you sent back from your own wallet.
 * Orboni holds no money, so recording a refund sends nothing.
 */
class RefundService extends AbstractService
{
    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string, timeout?: int|float, max_retries?: int}  $opts
     */
    public function create(array $params, array $opts = []): OrboniObject
    {
        return $this->client->request('POST', '/refunds', $params, $opts);
    }

    /**
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     */
    public function retrieve(string $id, array $opts = []): OrboniObject
    {
        return $this->client->request('GET', $this->path('/refunds', $id), null, $opts);
    }

    /**
     * Refunds, newest first; `checkout_session` keeps only that session's.
     *
     * @param  array<string, mixed>  $params
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->client->requestCollection('/refunds', $params, $opts);
    }

    /**
     * Takes back a refund recorded by mistake.
     *
     * @param  array{idempotency_key?: string, timeout?: int|float, max_retries?: int}  $opts
     */
    public function void(string $id, array $opts = []): OrboniObject
    {
        return $this->client->request('POST', $this->path('/refunds', $id, '/void'), null, $opts);
    }
}

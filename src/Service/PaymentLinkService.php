<?php

declare(strict_types=1);

namespace Orboni\Service;

use Orboni\Collection;
use Orboni\OrboniObject;

/** `/v1/payment_links`: one address that makes a session for each buyer who opens it. */
class PaymentLinkService extends AbstractService
{
    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string, timeout?: int|float, max_retries?: int}  $opts
     */
    public function create(array $params, array $opts = []): OrboniObject
    {
        return $this->client->request('POST', '/payment_links', $params, $opts);
    }

    /**
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     */
    public function retrieve(string $id, array $opts = []): OrboniObject
    {
        return $this->client->request('GET', $this->path('/payment_links', $id), null, $opts);
    }

    /**
     * Changes the fields given; `'active' => false` stops it taking payments.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string, timeout?: int|float, max_retries?: int}  $opts
     */
    public function update(string $id, array $params, array $opts = []): OrboniObject
    {
        return $this->client->request('POST', $this->path('/payment_links', $id), $params, $opts);
    }

    /**
     * Links, newest first; `active` keeps only those that take payments (true) or are switched off (false).
     *
     * @param  array<string, mixed>  $params
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->client->requestCollection('/payment_links', $params, $opts);
    }
}

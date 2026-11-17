<?php

declare(strict_types=1);

namespace Orboni\Service;

use Orboni\Collection;
use Orboni\OrboniObject;

/** `/v1/checkout/sessions`: one payment you wait for, and its payment page. */
class CheckoutSessionService extends AbstractService
{
    /**
     * Makes a session; send the buyer to its `url`.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string, timeout?: int|float, max_retries?: int}  $opts
     */
    public function create(array $params, array $opts = []): OrboniObject
    {
        return $this->client->request('POST', '/checkout/sessions', $params, $opts);
    }

    /**
     * A session as it is now: read it back before you fulfil an order.
     *
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     */
    public function retrieve(string $id, array $opts = []): OrboniObject
    {
        return $this->client->request('GET', $this->path('/checkout/sessions', $id), null, $opts);
    }

    /**
     * Sessions, newest first: `limit`, `starting_after`, `ending_before`,
     * `created`, `status`, `payment_status`, `client_reference_id`, `customer_email`.
     *
     * @param  array<string, mixed>  $params
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->client->requestCollection('/checkout/sessions', $params, $opts);
    }

    /**
     * Closes an open session now. Money sent to it later is held for your review.
     *
     * @param  array{idempotency_key?: string, timeout?: int|float, max_retries?: int}  $opts
     */
    public function expire(string $id, array $opts = []): OrboniObject
    {
        return $this->client->request('POST', $this->path('/checkout/sessions', $id, '/expire'), null, $opts);
    }
}

<?php

declare(strict_types=1);

namespace Orboni\Service;

use Orboni\Collection;
use Orboni\OrboniObject;

/** `/v1/events`: what your webhooks were sent, kept so you can read them again. */
class EventService extends AbstractService
{
    /**
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     */
    public function retrieve(string $id, array $opts = []): OrboniObject
    {
        return $this->client->request('GET', $this->path('/events', $id), null, $opts);
    }

    /**
     * Events, newest first; `type` and `checkout_session` narrow them.
     *
     * @param  array<string, mixed>  $params
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->client->requestCollection('/events', $params, $opts);
    }

    /**
     * Sends the event to your endpoints again.
     *
     * @param  array{idempotency_key?: string, timeout?: int|float, max_retries?: int}  $opts
     */
    public function resend(string $id, array $opts = []): OrboniObject
    {
        return $this->client->request('POST', $this->path('/events', $id, '/resend'), null, $opts);
    }
}

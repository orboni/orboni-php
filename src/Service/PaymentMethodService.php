<?php

declare(strict_types=1);

namespace Orboni\Service;

use Orboni\Collection;

/** `/v1/payment_methods`: the coins and networks your project can be paid in now. One page, never paged. */
class PaymentMethodService extends AbstractService
{
    /**
     * `amount` (in `currency`'s smallest unit) leaves out the methods whose minimum is above it.
     *
     * @param  array{amount?: int, currency?: string}  $params
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->client->requestCollection('/payment_methods', $params, $opts, false);
    }
}

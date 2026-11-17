<?php

declare(strict_types=1);

namespace Orboni\Service;

use Orboni\Collection;

/** `/v1/currencies`: the currencies a price can be in, and their decimals. One page, never paged. */
class CurrencyService extends AbstractService
{
    /**
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     */
    public function list(array $opts = []): Collection
    {
        return $this->client->requestCollection('/currencies', [], $opts, false);
    }
}

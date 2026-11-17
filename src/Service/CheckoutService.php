<?php

declare(strict_types=1);

namespace Orboni\Service;

use Orboni\OrboniClient;

/** `$orboni->checkout`: holds `sessions`. */
class CheckoutService
{
    public readonly CheckoutSessionService $sessions;

    public function __construct(OrboniClient $client)
    {
        $this->sessions = new CheckoutSessionService($client);
    }
}

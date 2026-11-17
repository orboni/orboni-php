<?php

declare(strict_types=1);

namespace Orboni\Exception;

/** 429: too many requests or open sessions. getRetryAfter() says how long to wait. */
class RateLimitException extends OrboniException
{
    /** Seconds to wait, from the Retry-After header; null when it has none. */
    public function getRetryAfter(): ?int
    {
        $value = $this->getHeaders()['retry-after'] ?? null;

        return is_numeric($value) ? (int) ceil((float) $value) : null;
    }
}

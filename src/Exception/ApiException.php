<?php

declare(strict_types=1);

namespace Orboni\Exception;

/** 500, 503: Orboni's side, or an update in progress. Safe to retry with the same idempotency key. */
class ApiException extends OrboniException {}

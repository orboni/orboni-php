<?php

declare(strict_types=1);

namespace Orboni\Exception;

/** 400: an Idempotency-Key reused with other parameters; 409: its first request is still running. */
class IdempotencyException extends OrboniException {}

<?php

declare(strict_types=1);

namespace Orboni\Exception;

/** 401: no key, a wrong one or a revoked one. */
class AuthenticationException extends OrboniException {}

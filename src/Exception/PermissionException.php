<?php

declare(strict_types=1);

namespace Orboni\Exception;

/** 403: a suspended or closed account, or a live key before the account's email is confirmed. */
class PermissionException extends OrboniException {}

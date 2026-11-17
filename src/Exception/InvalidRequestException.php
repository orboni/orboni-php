<?php

declare(strict_types=1);

namespace Orboni\Exception;

/** 400, 404, 413, 422: a bad parameter (getParam() names it), no such object, or a body too large. */
class InvalidRequestException extends OrboniException {}

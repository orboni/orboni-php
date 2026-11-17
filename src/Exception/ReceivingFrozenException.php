<?php

declare(strict_types=1);

namespace Orboni\Exception;

/** 403: the owner froze receiving; live keys only read until it is unfrozen. */
class ReceivingFrozenException extends OrboniException {}

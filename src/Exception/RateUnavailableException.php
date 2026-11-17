<?php

declare(strict_types=1);

namespace Orboni\Exception;

/** 503: no current exchange rate for the price's currency. Try again shortly. */
class RateUnavailableException extends OrboniException {}

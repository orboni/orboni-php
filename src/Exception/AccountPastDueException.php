<?php

declare(strict_types=1);

namespace Orboni\Exception;

/** 402: live only, an Orboni invoice is past due. */
class AccountPastDueException extends OrboniException {}

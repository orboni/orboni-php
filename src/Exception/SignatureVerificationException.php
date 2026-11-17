<?php

declare(strict_types=1);

namespace Orboni\Exception;

/** A webhook whose signature, timestamp or body could not be verified. Answer it with 400. */
class SignatureVerificationException extends OrboniException {}

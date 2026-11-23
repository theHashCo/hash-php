<?php

declare(strict_types=1);

namespace Hash\Exception;

/** A call the SDK refused before sending it: a missing key, an empty id or a bad Idempotency-Key. */
class InvalidArgumentException extends \InvalidArgumentException
{
}

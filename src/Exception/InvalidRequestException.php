<?php

declare(strict_types=1);

namespace Hash\Exception;

/** 400 (`idempotency_key_required`), 405 (`method_not_allowed`) and any other 4xx without its own class. */
class InvalidRequestException extends HashException
{
}

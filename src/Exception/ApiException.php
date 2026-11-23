<?php

declare(strict_types=1);

namespace Hash\Exception;

/** 5xx: something failed on Hash's side (503 `php_jit_enabled` included). Retry with the same Idempotency-Key. */
class ApiException extends HashException
{
}

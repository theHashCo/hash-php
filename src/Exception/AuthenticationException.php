<?php

declare(strict_types=1);

namespace Hash\Exception;

/** 401 `invalid_api_key`: the key is missing, malformed, unknown, revoked or expired. */
class AuthenticationException extends HashException
{
}

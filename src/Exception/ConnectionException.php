<?php

declare(strict_types=1);

namespace Hash\Exception;

use Throwable;

/** No answer came: the network failed or the request timed out. getHttpStatus() is null. */
class ConnectionException extends HashException
{
    public function __construct(string $message, private readonly bool $timedOut = false, ?Throwable $previous = null)
    {
        parent::__construct($message, null, null, null, null, [], $previous);
    }

    /** True when the request ran out of time. */
    public function isTimeout(): bool
    {
        return $this->timedOut;
    }
}

<?php

declare(strict_types=1);

namespace Hash\Exception;

/** 429 `rate_limited`: wait getRetryAfter() seconds. */
class RateLimitException extends HashException
{
    /** Seconds to wait, from the `Retry-After` header or the body's `retryAfter`. Null when neither was sent. */
    public function getRetryAfter(): ?int
    {
        $header = $this->getHeader('retry-after');
        if ($header !== null && preg_match('/^\d+$/', trim($header)) === 1) {
            return (int) trim($header);
        }
        $v = $this->bodyField('retryAfter');

        return is_int($v) && $v >= 0 ? $v : null;
    }
}

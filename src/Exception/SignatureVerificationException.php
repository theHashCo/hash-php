<?php

declare(strict_types=1);

namespace Hash\Exception;

/** A webhook request whose `Hash-Signature` is missing, malformed, wrong or too old. Answer it with 400. */
class SignatureVerificationException extends HashException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}

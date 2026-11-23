<?php

declare(strict_types=1);

namespace Hash;

use Hash\Exception\SignatureVerificationException;
use Hash\Exception\UnexpectedValueException;

/**
 * Webhook verification without a client:
 *
 *     $event = \Hash\Webhook::constructEvent($rawBody, $_SERVER, $secret);
 */
final class Webhook
{
    /**
     * @param array<string, string|list<string>|null>|string $headers
     * @param string|list<string>                             $secret
     *
     * @throws SignatureVerificationException
     * @throws UnexpectedValueException
     */
    public static function constructEvent(string $rawBody, array|string $headers, string|array $secret, int $tolerance = Webhooks::DEFAULT_TOLERANCE, ?int $now = null): Event
    {
        return (new Webhooks())->constructEvent($rawBody, $headers, $secret, $tolerance, $now);
    }

    private function __construct()
    {
    }
}

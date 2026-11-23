<?php

declare(strict_types=1);

namespace Hash\HttpClient;

use Hash\Exception\ConnectionException;

/**
 * Sends one HTTP request. CurlClient is the default; Psr18Client wraps any PSR-18 client.
 * Implement it to plug in another transport (for example WordPress's wp_remote_request).
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param float                 $timeout        seconds for the whole request
     * @param float                 $connectTimeout seconds to connect
     *
     * @throws ConnectionException when no answer came
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response;
}

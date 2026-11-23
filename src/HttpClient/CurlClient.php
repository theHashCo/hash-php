<?php

declare(strict_types=1);

namespace Hash\HttpClient;

use Hash\Exception\ConnectionException;

/** The default transport: ext-curl, no other dependency. */
final class CurlClient implements HttpClientInterface
{
    /** @param array<int, mixed> $curlOptions extra CURLOPT_* options, such as a proxy */
    public function __construct(private readonly array $curlOptions = [])
    {
    }

    public static function isAvailable(): bool
    {
        return \function_exists('curl_init');
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new ConnectionException('Could not start curl.');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        // Stop curl from adding "Expect: 100-continue", which delays small bodies.
        $lines[] = 'Expect:';

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($connectTimeout * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new answer (after 100 Continue): forget the previous headers
                }

                return \strlen($line);
            },
        ];
        if (\defined('CURLOPT_PROTOCOLS') && \defined('CURLPROTO_HTTPS') && \defined('CURLPROTO_HTTP')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $this->curlOptions + $options);

        $result = curl_exec($ch);
        if ($result === false || !\is_string($result)) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            $timedOut = $errno === CURLE_OPERATION_TIMEOUTED;
            throw new ConnectionException($timedOut ? sprintf('No answer from Hash within %.1f seconds.', $timeout) : "Could not reach Hash: $error (curl $errno)", $timedOut);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        /** @var array<string, list<string>> $responseHeaders */
        return new Response($status, $responseHeaders, $result);
    }
}

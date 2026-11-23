<?php

declare(strict_types=1);

namespace Hash\HttpClient;

/** An HTTP answer: status, headers (lowercase names) and body. */
final class Response
{
    /** @var array<string, list<string>> */
    public readonly array $headers;

    /** @param array<string, list<string>|string> $headers */
    public function __construct(public readonly int $status, array $headers, public readonly string $body)
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            foreach ((array) $values as $value) {
                $normalized[strtolower((string) $name)][] = (string) $value;
            }
        }
        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}

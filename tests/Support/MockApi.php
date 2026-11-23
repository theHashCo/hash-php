<?php

declare(strict_types=1);

namespace Hash\Tests\Support;

/**
 * The request handler behind tests/Support/router.php: a stand-in for the Hash API.
 *
 * Every request is checked against the spec (method, path, API key, Idempotency-Key, query
 * parameters and body); anything wrong is recorded in the state's `problems`. Unless a test
 * queued its own answer, it answers with the example the spec gives for the operation.
 */
final class MockApi
{
    public function __construct(private readonly State $state)
    {
    }

    /**
     * @param array<string, string> $headers lowercase names
     *
     * @return array{status: int, headers: array<string, string>, body: string, delay: float}
     */
    public function handle(string $method, string $uri, array $headers, string $rawBody): array
    {
        $parts = parse_url($uri);
        $fullPath = $parts['path'] ?? '/';
        $path = (string) preg_replace('#^/api/v1#', '', $fullPath);
        $rawQuery = $parts['query'] ?? '';
        $problems = [];

        $operation = null;
        $pathMatches = false;
        foreach (Spec::operations() as $o) {
            if (preg_match($o['regex'], $path) === 1) {
                $pathMatches = true;
                if ($o['method'] === $method) {
                    $operation = $o;
                }
            }
        }

        $this->state->update(function (array $s) use ($method, $uri, $path, $rawQuery, $headers, $rawBody, $operation): array {
            $s['requests'][] = [
                'method' => $method,
                'uri' => $uri,
                'path' => $path,
                'query' => $rawQuery,
                'headers' => $headers,
                'body' => $rawBody,
                'operation' => $operation['id'] ?? null,
            ];

            return $s;
        });

        if (!str_starts_with($fullPath, '/api/v1/')) {
            $problems[] = "$method $fullPath: not under /api/v1";
        }
        if (!$pathMatches) {
            $this->addProblems(array_merge($problems, ["$method $path: no such path in the spec"]));

            return $this->json(404, ['message' => 'Not found.']);
        }
        if ($operation === null) {
            $this->addProblems(array_merge($problems, ["$method $path: method not in the spec"]));

            return $this->json(405, ['error' => 'method_not_allowed', 'message' => 'Not allowed.']);
        }

        $problems = array_merge($problems, $this->check($operation, $headers, $rawQuery, $rawBody));
        $this->addProblems($problems);

        $queued = null;
        $this->state->update(function (array $s) use (&$queued): array {
            $queued = array_shift($s['queue']);

            return $s;
        });
        if (\is_array($queued)) {
            $body = \array_key_exists('raw', $queued) ? (string) $queued['raw'] : (\array_key_exists('body', $queued) ? (string) json_encode($queued['body'], JSON_UNESCAPED_SLASHES) : '');

            return ['status' => (int) $queued['status'], 'headers' => ['Content-Type' => 'application/json'] + ($queued['headers'] ?? []), 'body' => $body, 'delay' => (float) ($queued['delay'] ?? 0)];
        }
        $example = Spec::successExample($operation['op']);

        return $this->json($example['status'], $example['body']);
    }

    /**
     * @param array{id: string, method: string, path: string, regex: string, op: array<string, mixed>, params: list<array<string, mixed>>} $o
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function check(array $o, array $headers, string $rawQuery, string $rawBody): array
    {
        $where = "{$o['method']} {$o['path']}";
        $problems = [];
        $fail = static function (string $m) use (&$problems, $where): void {
            $problems[] = "$where: $m";
        };

        $ua = $headers['user-agent'] ?? '';
        if (preg_match('#^hash-php/\d+\.\d+\.\d+ PHP/#', $ua) !== 1) {
            $fail("User-Agent $ua is not hash-php/x.y.z");
        }

        $public = isset($o['op']['security']) && $o['op']['security'] === [];
        if (!$public) {
            $auth = $headers['authorization'] ?? '';
            if (preg_match('/^Bearer hash_(live|sandbox)_sk_[A-Za-z0-9]{40}$/', $auth) !== 1) {
                $fail("Authorization is not a bearer secret key: $auth");
            }
        } elseif (isset($headers['authorization'])) {
            $fail('a public operation was sent an API key');
        }

        foreach ($o['params'] as $p) {
            if ($p['in'] !== 'header') {
                continue;
            }
            $value = $headers[strtolower($p['name'])] ?? null;
            if ($value === null) {
                if ($p['required'] ?? false) {
                    $fail("missing header {$p['name']}");
                }
                continue;
            }
            if ($p['name'] === 'Idempotency-Key' && (trim($value) === '' || \strlen($value) > 255)) {
                $fail('Idempotency-Key is not 1 to 255 bytes');
            }
            foreach (Spec::validate($p['schema'], $value) as $e) {
                $fail("header {$p['name']} $e");
            }
        }

        $known = [];
        foreach ($o['params'] as $p) {
            if ($p['in'] === 'query') {
                $known[$p['name']] = $p;
            }
        }
        $seen = [];
        foreach ($rawQuery === '' ? [] : explode('&', $rawQuery) as $pair) {
            [$name, $value] = array_map('rawurldecode', array_pad(explode('=', $pair, 2), 2, ''));
            $seen[$name] = true;
            $p = $known[$name] ?? null;
            if ($p === null) {
                $fail("unknown query parameter $name");
                continue;
            }
            $parsed = $value;
            if (($p['schema']['type'] ?? null) === 'array') {
                $parsed = explode(',', $value);
            } elseif (($p['schema']['type'] ?? null) === 'integer' && preg_match('/^-?\d+$/', $value) === 1) {
                $parsed = (int) $value;
            }
            foreach (Spec::validate($p['schema'], $parsed) as $e) {
                $fail("query $name=$value $e");
            }
        }
        foreach ($known as $name => $p) {
            if (($p['required'] ?? false) && !isset($seen[$name])) {
                $fail("missing query parameter $name");
            }
        }

        $rb = $o['op']['requestBody'] ?? null;
        if ($rb === null) {
            if ($rawBody !== '') {
                $fail('sent a body to an operation that takes none');
            }

            return $problems;
        }
        if ($rawBody === '') {
            if ($rb['required'] ?? false) {
                $fail('missing required body');
            }

            return $problems;
        }
        if (!str_starts_with($headers['content-type'] ?? '', 'application/json')) {
            $fail('body sent without Content-Type: application/json');
        }
        try {
            $decoded = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $fail('body is not JSON');

            return $problems;
        }
        foreach (Spec::validate($rb['content']['application/json']['schema'], $decoded) as $e) {
            $fail("body $e");
        }

        return $problems;
    }

    /** @param list<string> $problems */
    private function addProblems(array $problems): void
    {
        if ($problems === []) {
            return;
        }
        $this->state->update(static function (array $s) use ($problems): array {
            $s['problems'] = array_merge($s['problems'], $problems);

            return $s;
        });
    }

    /** @return array{status: int, headers: array<string, string>, body: string, delay: float} */
    private function json(int $status, mixed $body): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 'delay' => 0.0];
    }
}

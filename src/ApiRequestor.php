<?php

declare(strict_types=1);

namespace Hash;

use Closure;
use Hash\Exception\ApiException;
use Hash\Exception\AuthenticationException;
use Hash\Exception\ConflictException;
use Hash\Exception\ConnectionException;
use Hash\Exception\HashException;
use Hash\Exception\InvalidArgumentException;
use Hash\Exception\InvalidRequestException;
use Hash\Exception\NotFoundException;
use Hash\Exception\PermissionException;
use Hash\Exception\RateLimitException;
use Hash\Exception\UnexpectedValueException;
use Hash\Exception\ValidationException;
use Hash\HttpClient\HttpClientInterface;
use Hash\HttpClient\Response;
use Hash\Service\Operations;

/**
 * Sends requests: authentication, idempotency keys, retries, timeouts and error mapping.
 *
 * @internal used by the services; call HashClient instead
 */
final class ApiRequestor
{
    /** A 429 asking to wait longer than this is not retried: the exception is thrown instead. */
    public const MAX_RETRY_AFTER_SECONDS = 60;

    /** @var Closure(float): void */
    private Closure $sleep;

    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param (callable(float): void)|null $sleep  waits that many seconds; tests replace it
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly HttpClientInterface $http,
        private readonly int $maxRetries,
        private readonly float $timeout,
        private readonly float $connectTimeout,
        private readonly string $userAgent,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep !== null ? Closure::fromCallable($sleep) : static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->random = static fn (): float => mt_rand() / mt_getrandmax();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'maxRetries' => $this->maxRetries, 'timeout' => $this->timeout, 'userAgent' => $this->userAgent];
    }

    /**
     * @param array<string, string>     $pathParams
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $options    idempotency_key, timeout, max_retries
     *
     * @return array<string, mixed> the decoded answer
     *
     * @throws HashException
     */
    public function request(string $operationId, array $pathParams, ?array $query, ?array $body, array $options = []): array
    {
        $op = self::operation($operationId);
        $path = (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($pathParams): string {
            $value = $pathParams[$m[1]] ?? '';
            if ($value === '') {
                throw new InvalidArgumentException("$m[1] must be a non-empty string.");
            }

            return rawurlencode($value);
        }, $op['path']);
        $url = $this->baseUrl . $path . self::buildQuery($query ?? []);

        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent];
        if ($op['auth']) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $payload = null;
        if ($body !== null && $op['body'] !== 'none') {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        } elseif ($op['body'] === 'required') {
            throw new InvalidArgumentException("$operationId needs a request body.");
        }

        $idempotencyKey = null;
        if ($op['idempotency_key'] || $op['method'] === 'POST' || $op['method'] === 'PATCH') {
            $idempotencyKey = isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : self::uuid4();
            if (trim($idempotencyKey) === '' || \strlen($idempotencyKey) > 255) {
                throw new InvalidArgumentException('idempotency_key must be 1 to 255 bytes and not blank.');
            }
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        // Only a GET or a request carrying an Idempotency-Key is safe to send twice.
        $retryable = $op['method'] === 'GET' || $idempotencyKey !== null;
        $maxRetries = $retryable ? self::nonNegativeInt($options['max_retries'] ?? $this->maxRetries, 'max_retries') : 0;
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : $this->timeout;

        for ($attempt = 0; ; ++$attempt) {
            try {
                $response = $this->http->send($op['method'], $url, $headers, $payload, $timeout, min($this->connectTimeout, $timeout));
            } catch (ConnectionException $e) {
                if ($attempt < $maxRetries) {
                    ($this->sleep)($this->backoff($attempt));
                    continue;
                }
                throw $e;
            }

            if ($response->status >= 200 && $response->status < 300) {
                return self::decodeSuccess($response);
            }

            $error = self::exceptionFor($response);
            $delay = $attempt < $maxRetries ? $this->retryDelay($error, $attempt) : null;
            if ($delay === null) {
                throw $error;
            }
            ($this->sleep)($delay);
        }
    }

    /**
     * @template T
     *
     * @param array<string, string>     $pathParams
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>      $options
     * @param Closure(array<string, mixed>): T $map
     *
     * @return Page<T>
     *
     * @throws HashException
     */
    public function list(string $operationId, array $pathParams, ?array $query, array $options, Closure $map): Page
    {
        $query ??= [];
        $fetch = function (?string $cursor) use ($operationId, $pathParams, $query, $options, $map, &$fetch): Page {
            $q = $query;
            if ($cursor !== null) {
                $q['cursor'] = $cursor;
            }
            $envelope = $this->request($operationId, $pathParams, $q, null, $options);
            $data = $envelope['data'] ?? null;
            if (!\is_array($data) || !array_is_list($data)) {
                throw new UnexpectedValueException('Hash answered a list without a data array.');
            }
            $items = [];
            foreach ($data as $item) {
                if (!\is_array($item)) {
                    throw new UnexpectedValueException('Hash answered a list with an item that is not an object.');
                }
                /** @var array<string, mixed> $item */
                $items[] = $map($item);
            }
            $next = $envelope['next_cursor'] ?? null;

            /** @var Closure(?string): Page<T> $fetch */
            return new Page($items, ($envelope['has_more'] ?? false) === true, \is_string($next) ? $next : null, $fetch);
        };

        $initial = $query['cursor'] ?? null;

        return $fetch(\is_string($initial) ? $initial : null);
    }

    /**
     * @return array{method: string, path: string, auth: bool, idempotency_key: bool, body: string, query: list<string>, status: int, list: bool}
     */
    private static function operation(string $operationId): array
    {
        return Operations::ALL[$operationId] ?? throw new InvalidArgumentException("Unknown operation $operationId.");
    }

    /**
     * Serializes query parameters the way the spec describes: lists comma separated,
     * `created[gte]` style for nested arrays.
     *
     * @param array<string, mixed> $query
     */
    public static function buildQuery(array $query): string
    {
        $parts = [];
        $add = static function (string $key, mixed $value) use (&$add, &$parts): void {
            if ($value === null) {
                return;
            }
            if (\is_array($value)) {
                if (array_is_list($value)) {
                    if ($value === []) {
                        return;
                    }
                    $parts[] = rawurlencode($key) . '=' . implode(',', array_map(static fn (mixed $v): string => rawurlencode(self::scalarString($v)), $value));

                    return;
                }
                foreach ($value as $sub => $v) {
                    $add($key . '[' . $sub . ']', $v);
                }

                return;
            }
            if ($value instanceof \DateTimeInterface) {
                $value = (new \DateTimeImmutable('@' . $value->getTimestamp()))->format('Y-m-d\TH:i:s\Z');
            }
            $parts[] = rawurlencode($key) . '=' . rawurlencode(self::scalarString($value));
        };
        foreach ($query as $key => $value) {
            $add((string) $key, $value);
        }

        return $parts === [] ? '' : '?' . implode('&', $parts);
    }

    private static function scalarString(mixed $v): string
    {
        if (\is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (\is_scalar($v)) {
            return (string) $v;
        }
        throw new InvalidArgumentException('Query values must be strings, numbers or lists of them.');
    }

    /** A random UUID v4. */
    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** @return array<string, mixed> */
    private static function decodeSuccess(Response $response): array
    {
        if ($response->body === '') {
            return [];
        }
        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new UnexpectedValueException("Hash answered {$response->status} with a body that is not JSON.", $response->status, null, $response->body, null, $response->headers, $e);
        }
        if (!\is_array($data)) {
            throw new UnexpectedValueException("Hash answered {$response->status} with JSON that is not an object.", $response->status, null, $response->body, null, $response->headers);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** Builds the exception for a non-2xx answer. */
    public static function exceptionFor(Response $response): HashException
    {
        $json = null;
        if ($response->body !== '') {
            try {
                $json = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                $json = null;
            }
        }
        $code = \is_array($json) && \is_string($json['error'] ?? null) ? $json['error'] : null;
        $apiMessage = \is_array($json) && \is_string($json['message'] ?? null) ? $json['message'] : null;
        $message = implode(': ', array_filter(["Hash answered {$response->status}", $code, $apiMessage], static fn (?string $s): bool => $s !== null && $s !== ''));
        $args = [$message, $response->status, $code, $response->body, $json, $response->headers];
        $status = $response->status;

        return match (true) {
            $status === 401 => new AuthenticationException(...$args),
            $status === 403 => new PermissionException(...$args),
            $status === 404 => new NotFoundException(...$args),
            $status === 409 => new ConflictException(...$args),
            $status === 422 => new ValidationException(...$args),
            $status === 429 => new RateLimitException(...$args),
            $status >= 500 => new ApiException(...$args),
            default => new InvalidRequestException(...$args),
        };
    }

    /** Exponential backoff with jitter: about 0.5 s, 1 s, 2 s ... capped at 8 s. */
    private function backoff(int $attempt): float
    {
        $base = min(0.5 * (2 ** $attempt), 8.0);

        return round($base * (0.75 + ($this->random)() * 0.5), 3);
    }

    /**
     * Seconds to wait before retrying after this exception, or null when it must not be retried.
     * Retried: 429 (after Retry-After), 5xx and 409 idempotency_key_in_progress. Never another 4xx.
     */
    private function retryDelay(HashException $e, int $attempt): ?float
    {
        $status = $e->getHttpStatus();
        if ($e instanceof RateLimitException) {
            $after = $e->getRetryAfter();
            if ($after === null) {
                return $this->backoff($attempt);
            }

            return $after > self::MAX_RETRY_AFTER_SECONDS ? null : (float) $after;
        }
        if ($status === 409 && $e->getErrorCode() === 'idempotency_key_in_progress') {
            return $this->backoff($attempt);
        }
        if ($status !== null && $status >= 500) {
            $header = $e->getHeader('retry-after');
            if ($header !== null && preg_match('/^\d+$/', trim($header)) === 1 && (int) $header <= self::MAX_RETRY_AFTER_SECONDS) {
                return (float) (int) $header;
            }

            return $this->backoff($attempt);
        }

        return null;
    }

    private static function nonNegativeInt(mixed $value, string $name): int
    {
        if (!\is_int($value) || $value < 0) {
            throw new InvalidArgumentException("$name must be a whole number, 0 or more.");
        }

        return $value;
    }
}

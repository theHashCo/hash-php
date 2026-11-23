<?php

declare(strict_types=1);

namespace Hash\Exception;

use RuntimeException;
use Throwable;

/**
 * The base of every exception the SDK throws. Catch it to handle any Hash failure.
 *
 * Branch on the class, getHttpStatus() and getErrorCode(), never on the message.
 */
abstract class HashException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $headers lowercase names
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $rawBody = null,
        private readonly mixed $jsonBody = null,
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /** The HTTP status, or null when no answer came. */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** The API's `error` code, such as `duplicate_order_id`, or null when the answer had none. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** A request id, when Hash sent one in an `X-Request-Id` header. Null otherwise. */
    public function getRequestId(): ?string
    {
        return $this->getHeader('x-request-id');
    }

    /** The answer body exactly as received, or null. */
    public function getRawBody(): ?string
    {
        return $this->rawBody;
    }

    /** The decoded JSON body (an array), or null. */
    public function getJsonBody(): mixed
    {
        return $this->jsonBody;
    }

    /** @return array<string, list<string>> the answer headers, with lowercase names */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** A field of the JSON body, or null. */
    protected function bodyField(string $key): mixed
    {
        return is_array($this->jsonBody) ? ($this->jsonBody[$key] ?? null) : null;
    }
}

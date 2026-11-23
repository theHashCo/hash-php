<?php

declare(strict_types=1);

namespace Hash\Exception;

/**
 * 422: a validation failure (getErrors() maps each field to its messages, and getErrorCode() is null),
 * or `idempotency_key_reused`, `idempotency_key_unreadable` or `invalid_cursor` (with an error code).
 */
class ValidationException extends HashException
{
    /** @return array<string, list<string>> field errors, such as ['price_usd' => ['The price usd field is required.']] */
    public function getErrors(): array
    {
        $errors = $this->bodyField('errors');
        if (!is_array($errors)) {
            return [];
        }
        $out = [];
        foreach ($errors as $field => $messages) {
            if (is_array($messages)) {
                $out[(string) $field] = array_values(array_map(static fn (mixed $m): string => is_scalar($m) ? (string) $m : '', $messages));
            }
        }

        return $out;
    }
}

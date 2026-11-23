<?php

declare(strict_types=1);

namespace Hash\Exception;

/**
 * 409: `duplicate_order_id` (see getInvoiceId()), `idempotency_key_in_progress`, `invalid_state`,
 * `payment_in_progress`, `preview_stale` (see getPreview()), `secret_shown_once` (see getResource()) and others.
 */
class ConflictException extends HashException
{
    /** On `duplicate_order_id`: the invoice that already has this `order_id`. */
    public function getInvoiceId(): ?string
    {
        $v = $this->bodyField('invoice_id');

        return is_string($v) ? $v : null;
    }

    /** @return array<string, mixed>|null on `preview_stale`: the current preview */
    public function getPreview(): ?array
    {
        $v = $this->bodyField('preview');

        /** @var array<string, mixed>|null */
        return is_array($v) ? $v : null;
    }

    /** @return array<string, mixed>|null on `secret_shown_once`: the endpoint that was created, without its secret */
    public function getResource(): ?array
    {
        $v = $this->bodyField('resource');

        /** @var array<string, mixed>|null */
        return is_array($v) ? $v : null;
    }
}

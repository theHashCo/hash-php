<?php

declare(strict_types=1);

namespace Hash;

use Hash\Enum\EventType;
use Hash\Exception\UnexpectedValueException;
use Hash\Model\Invoice;
use Hash\Model\InvoiceEventDetails;
use Hash\Model\Wallet;
use Hash\Model\WalletDepositDetails;
use Hash\Model\WebhookTest;
use Hash\Model\Withdrawal;
use JsonSerializable;

/**
 * A webhook event: `{id, type, created_at, data}`. `data` holds the object the event is about
 * (`invoice`, `withdrawal`, `wallet` or `test`) and `details`. Read them typed with invoice(),
 * withdrawal(), wallet() or test(), or as arrays through $data.
 *
 * The event id stays the same on every retry and resend: deduplicate on it.
 */
final class Event implements JsonSerializable
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $raw
     */
    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $created_at,
        public readonly array $data,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $id = $raw['id'] ?? null;
        $type = $raw['type'] ?? null;
        $data = $raw['data'] ?? null;
        if (!\is_string($id) || !\is_string($type) || !\is_array($data)) {
            throw new UnexpectedValueException('The webhook body is not a Hash event (id, type, data).');
        }
        $createdAt = $raw['created_at'] ?? '';

        /** @var array<string, mixed> $data */
        return new self($id, $type, \is_string($createdAt) ? $createdAt : '', $data, $raw);
    }

    /** True for `invoice.*` events, which carry an invoice. */
    public function isInvoiceEvent(): bool
    {
        return str_starts_with($this->type, 'invoice.');
    }

    /** True for `withdrawal.*` events, which carry a withdrawal (or refund). */
    public function isWithdrawalEvent(): bool
    {
        return str_starts_with($this->type, 'withdrawal.');
    }

    /** True for `webhook.test`, sent by $hash->webhookEndpoints->test(): nothing happened. */
    public function isTest(): bool
    {
        return $this->type === EventType::WEBHOOK_TEST;
    }

    /** The invoice of an `invoice.*` event. */
    public function invoice(): Invoice
    {
        return Invoice::fromArray($this->object('invoice'));
    }

    /** The details of an `invoice.*` event: payments, fee split, flags and decision. */
    public function invoiceDetails(): InvoiceEventDetails
    {
        return InvoiceEventDetails::fromArray($this->object('details'));
    }

    /** The withdrawal (or refund) of a `withdrawal.*` event. */
    public function withdrawal(): Withdrawal
    {
        return Withdrawal::fromArray($this->object('withdrawal'));
    }

    /** The receiving wallet of a `wallet.deposit_confirmed` event. */
    public function wallet(): Wallet
    {
        return Wallet::fromArray($this->object('wallet'));
    }

    /** The deposit of a `wallet.deposit_confirmed` event. */
    public function walletDepositDetails(): WalletDepositDetails
    {
        return WalletDepositDetails::fromArray($this->object('details'));
    }

    /** The test of a `webhook.test` event. */
    public function test(): WebhookTest
    {
        return WebhookTest::fromArray($this->object('test'));
    }

    /** @return array<string, mixed> the event as Hash sent it */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }

    /** @return array<string, mixed> */
    private function object(string $key): array
    {
        $v = $this->data[$key] ?? null;
        if (!\is_array($v)) {
            throw new UnexpectedValueException("A {$this->type} event has no data.$key.");
        }

        /** @var array<string, mixed> $v */
        return $v;
    }
}

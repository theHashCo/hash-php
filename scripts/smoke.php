<?php

declare(strict_types=1);

// End-to-end smoke test against a real Hash sandbox. Not run in CI.
//
//   HASH_SECRET_KEY=hash_sandbox_sk_... composer smoke
//
// Optional:
//   HASH_BASE_URL            defaults to https://api.hash.bh/api/v1
//   HASH_SMOKE_WEBHOOK_URL   a public https URL; used to create an endpoint when the sandbox has no enabled one
//
// It creates a $1.00 sandbox invoice, reads it back, finds it in the list, cancels it, reads balances
// and the webhook source IPs, and sends a webhook.test event to an enabled endpoint. A step whose
// scope the key lacks is skipped, not failed. It refuses live keys.

require dirname(__DIR__) . '/vendor/autoload.php';

use Hash\Exception\HashException;
use Hash\Exception\PermissionException;
use Hash\HashClient;
use Hash\Model\Invoice;

$key = (string) getenv('HASH_SECRET_KEY');
if (!str_starts_with($key, 'hash_sandbox_sk_')) {
    fwrite(STDERR, "Set HASH_SECRET_KEY to a sandbox secret key (hash_sandbox_sk_...). Live keys are refused.\n");
    exit(2);
}
$options = ['app_info' => ['name' => 'hash-php-smoke']];
if (getenv('HASH_BASE_URL')) {
    $options['base_url'] = (string) getenv('HASH_BASE_URL');
}
$hash = new HashClient($key, $options);
$failed = 0;

$step = static function (string $name, callable $fn) use (&$failed): void {
    try {
        $out = $fn();
        echo 'ok    ' . $name . ($out ? ": $out" : '') . "\n";
    } catch (PermissionException $e) {
        if ($e->getErrorCode() === 'insufficient_scope') {
            echo "skip  $name: the key lacks {$e->getRequiredScope()}\n";

            return;
        }
        ++$failed;
        echo "FAIL  $name: " . $e::class . ': ' . $e->getMessage() . "\n";
    } catch (HashException $e) {
        ++$failed;
        echo "FAIL  $name: " . $e::class . ': ' . $e->getMessage() . "\n";
    }
};

$orderId = 'smoke-' . time();
/** @var Invoice|null $invoice */
$invoice = null;

$step('create an invoice', static function () use ($hash, $orderId, &$invoice): string {
    $invoice = $hash->invoices->create([
        'price_usd' => '1.00',
        'order_id' => $orderId,
        'description' => 'Hash SDK smoke test',
        'metadata' => ['smoke' => true],
    ]);

    return "{$invoice->id} {$invoice->status} {$invoice->pay_url}";
});

if ($invoice instanceof Invoice) {
    $id = $invoice->id;
    $step('retrieve it', static fn (): string => $hash->invoices->retrieve($id)->status);
    $step('find it by order_id in the list', static function () use ($hash, $orderId, $id): string {
        $page = $hash->invoices->list(['order_id' => $orderId]);
        if (($page->data[0] ?? null)?->id !== $id) {
            throw new \Hash\Exception\UnexpectedValueException('not in the list');
        }

        return count($page) . ' found';
    });
    $step('cancel it', static fn (): string => $hash->invoices->cancel($id)->status);
}

$step('page through recent invoices', static function () use ($hash): string {
    $n = 0;
    foreach ($hash->invoices->list(['limit' => 5])->autoPagingIterator() as $_) {
        if (++$n >= 15) {
            break;
        }
    }

    return "$n read";
});
$step('read balances', static fn (): string => count($hash->balances->retrieve()->balances) . ' coins');
$step('read webhook source IPs', static fn (): string => (string) json_encode($hash->meta->webhookIps()));

$step('send a webhook.test event', static function () use ($hash): string {
    $endpoint = null;
    foreach ($hash->webhookEndpoints->list()->autoPagingIterator() as $e) {
        if ($e->enabled) {
            $endpoint = $e;
            break;
        }
    }
    $url = getenv('HASH_SMOKE_WEBHOOK_URL');
    if ($endpoint === null && $url) {
        $created = $hash->webhookEndpoints->create(['url' => $url, 'events' => ['invoice.paid']]);
        echo "      created {$created->id}; its secret (shown once): {$created->secret}\n";
        $endpointId = $created->id;
        $endpointUrl = $created->url;
    } elseif ($endpoint !== null) {
        $endpointId = $endpoint->id;
        $endpointUrl = $endpoint->url;
    } else {
        return 'no enabled endpoint (set HASH_SMOKE_WEBHOOK_URL to create one)';
    }
    $delivery = $hash->webhookEndpoints->test($endpointId);
    $recent = $hash->webhookDeliveries->list(['endpoint_id' => $endpointId, 'limit' => 3]);

    return "{$delivery->id} {$delivery->status} to $endpointUrl; " . count($recent) . ' recent deliveries listed';
});

echo $failed ? "\n$failed step(s) failed.\n" : "\nAll steps passed.\n";
exit($failed ? 1 : 0);

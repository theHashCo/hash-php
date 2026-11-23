# Hash PHP Library

The official PHP library for the [Hash](https://hash.bh) API.

## Installation

```bash
composer require hashco/hash-php
```

Requires PHP 8.1 or later with `ext-curl`. Any PSR-18 client can be used instead. No dependencies.

## Usage

Create an invoice on your server and send the customer to the hosted checkout:

```php
use Hash\HashClient;

$hash = new HashClient(getenv('HASH_SECRET_KEY'));

$invoice = $hash->invoices->create([
    'price_usd' => '48.50',
    'order_id' => '7731',
    'success_url' => 'https://shop.example/orders/7731',
]);

header('Location: '.$invoice->pay_url, true, 303);
```

Ship the order when a verified `invoice.paid` webhook arrives, never on the redirect:

```php
use Hash\Exception\SignatureVerificationException;
use Hash\Webhook;

try {
    $event = Webhook::constructEvent(
        file_get_contents('php://input'), $_SERVER, getenv('HASH_WEBHOOK_SECRET')
    );
} catch (SignatureVerificationException) {
    http_response_code(400);
    exit;
}

if ($event->type === 'invoice.paid') {
    markOrderPaid($event->invoice()->order_id, $event->id);
}
```

Lists can be paged through with `autoPagingIterator()`:

```php
foreach ($hash->invoices->list(['status' => 'paid'])->autoPagingIterator() as $invoice) {
    echo $invoice->id;
}
```

Every `POST` and `PATCH` is sent with an `Idempotency-Key`, so retries are safe. Failures throw typed exceptions such as `ConflictException`, `ValidationException` and `RateLimitException`.

## Documentation

The full documentation is at [docs.hash.bh](https://docs.hash.bh).

## Security

If you discover a security vulnerability, please report it privately through [GitHub](https://github.com/theHashCo/hash-php/security/advisories/new) instead of opening an issue.

## License

The Hash PHP library is open-sourced software licensed under the [MIT license](LICENSE).

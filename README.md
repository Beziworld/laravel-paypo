# Laravel PayPo

[![tests](https://github.com/beziworld/laravel-paypo/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-paypo/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-paypo.svg)](https://packagist.org/packages/beziworld/laravel-paypo)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-paypo.svg)](LICENSE.md)

[PayPo](https://paypo.pl) "buy now, pay later" (deferred payments) for Laravel, built on the PayPo REST API v3:

- transaction registration with the redirect URL of the PayPo form
- transaction status with settlement status and refunds
- order confirmation (`COMPLETED`), cancellation and full or partial refunds
- notification route with `X-PayPo-Signature` (HMAC-SHA256) verification and Laravel events
- OAuth2 client credentials with a cached token per merchant
- many merchants: named connections, runtime credentials with `using()` and a resolver for tenant notifications
- sandbox, production and country hosts (e.g. `api.paypo.ro`)

[Polska wersja dokumentacji](README.pl.md)

## Why this package

The only maintained PHP library is [answear/pay-po](https://packagist.org/packages/answear/pay-po). It is framework
agnostic and keeps one merchant in static global configuration. This package adds what a Laravel shop needs on
top of the API: a notification route with signature verification and events, several merchant accounts in one
application (including tenants whose credentials live in the database), tokens in the Laravel cache, `Http::fake`
friendly transport and typed data objects.

## Installation

```bash
composer require beziworld/laravel-paypo
php artisan vendor:publish --tag=paypo-config   # optional
```

```dotenv
PAYPO_CLIENT_ID=...            # merchant panel: OAuth ClientId
PAYPO_CLIENT_SECRET=...        # merchant panel: OAuth ClientSecret
PAYPO_MERCHANT_ID=...          # merchantId; notifications of other merchants are rejected
PAYPO_MERCHANT_API_KEY=...     # merchantApiKey; verifies the X-PayPo-Signature header
PAYPO_SHOP_ID=                 # optional point of sale (shopId)
PAYPO_SANDBOX=true             # api.sandbox.paypo.pl; false = api.paypo.pl
PAYPO_RETURN_URL=https://shop.example.com/paypo/return
PAYPO_CANCEL_URL=              # optional; the buyer resigned from PayPo
```

Notifications go to the package route `POST /paypo/notification/{connection}`; the URL of the connection is sent
as `notifyUrl` automatically.

## Payment flow

```php
use Beziworld\PayPo\Data\Address;
use Beziworld\PayPo\Data\Customer;
use Beziworld\PayPo\Data\TransactionRequest;
use Beziworld\PayPo\Enums\Shipment;
use Beziworld\PayPo\Facades\PayPo;
use Beziworld\PayPo\Support\Amount;

$created = PayPo::createTransaction(new TransactionRequest(
    referenceId: $order->number,
    amount: Amount::toMinor($order->total),          // "249.90" -> 24990 (grosz)
    customer: new Customer('Anna', 'Nowak', 'anna@example.com', '+48500123456'),
    billingAddress: new Address('Kredytowa', '9a', 'Warszawa', '00-950', flat: '3'),
    shippingAddress: null,                            // defaults to the billing address
    description: "Order {$order->number}",
    shipment: Shipment::ParcelLocker,
));

$order->update(['paypo_transaction_id' => $created->transactionId]);

return redirect()->away($created->redirectUrl);
```

The buyer comes back to the return URL with `?status=OK` or `?status=ERR`. The status parameter is only a hint
for the message shown in the browser; the order state comes from notifications or `PayPo::transaction()`.

### Notifications and events

```php
use Beziworld\PayPo\Events\TransactionAccepted;
use Beziworld\PayPo\Events\TransactionCompleted;
use Beziworld\PayPo\Events\TransactionStatusChanged;

Event::listen(function (TransactionAccepted $event) {
    $order = Order::where('paypo_transaction_id', $event->notification->transactionId)->firstOrFail();

    if ($event->notification->amount === $order->total_in_grosze) {
        $order->markAsPaidWithPayPo();     // ship the goods, then confirm (below)
    }
});
```

| Event | When |
|---|---|
| `TransactionStatusChanged` | every authentic status or amount change notification |
| `TransactionAccepted` | PayPo granted the deferred payment |
| `TransactionCompleted` | the order was confirmed and is ready to be settled |
| `TransactionRejected` | the deferred payment was refused (it may still become accepted) |
| `TransactionCanceled` | the transaction was cancelled |
| `SettlementReported` | the wire transfer specification was generated (`settlementStatus`, `message`) |

Every event carries the `Notification` data object and the connection name. PayPo repeats notifications for 24
hours until it receives HTTP 200 and statuses may arrive out of order, so listeners must be idempotent; compare
`TransactionStatus::rank()` with the stored status to ignore stale ones.

The signature is `base64(HMAC-SHA256("POST+" . path . "+" . json, merchantApiKey))`. Notifications with a missing
or wrong signature, of another merchant or with an unknown status get HTTP 400. Set
`PAYPO_VERIFY_STATUS=true` to additionally fetch the transaction from the API before the events are dispatched
(HTTP 503 makes PayPo retry when the API is unreachable).

Your own controller can use `PayPo::parseNotification($request->getContent(), $request->header('X-PayPo-Signature'), '/webhooks/paypo')`.

## Confirmation, cancellation, refunds, status

```php
PayPo::confirm($transactionId);                    // COMPLETED: the order was fulfilled, PayPo settles it
PayPo::cancel($transactionId);                     // before COMPLETED; ApiException::isConflict() afterwards
PayPo::refund($transactionId, 8655, "refund-{$return->id}");   // minor units, own refund id (max. 68 chars)

$transaction = PayPo::transaction($transactionId);
$transaction->status;                 // TransactionStatus::Completed
$transaction->settlementStatus;       // SettlementStatus::Paid
$transaction->amountDecimal();        // "162.45" (current value, after refunds)
$transaction->refunds;                // list<Refund>, or null when the extended response is not enabled
$transaction->findRefund("refund-{$return->id}");
```

Unconfirmed orders may be cancelled by PayPo after a period agreed in the merchant contract, so confirm orders
after shipping. A refund of an `ACCEPTED` transaction completes it automatically; refunds may never increase the
order value.

## Many merchants

```php
// config/paypo.php: 'connections' => ['default' => [...], 'outlet' => [...]]
PayPo::connection('outlet')->createTransaction($request);   // notifyUrl: /paypo/notification/outlet

// Credentials stored per tenant
PayPo::using($tenant->paypoCredentials())->transaction($id);

// Tenants that receive notifications: give them a name and a resolver
PayPo::resolveConnectionsUsing(fn (string $name) => Tenant::where('paypo_key', $name)->first()?->paypoCredentials());
PayPo::connection($tenant->paypo_key)->createTransaction($request);   // notifyUrl: /paypo/notification/{key}
```

Each merchant has its own cached OAuth token. Use `PAYPO_BASE_URL=https://api.paypo.ro/v3` and
`PAYPO_CURRENCY=RON` (or the same keys in a connection) for the Romanian market.

## Errors

| Exception | When |
|---|---|
| `ApiException` | PayPo rejected the request or could not be reached: `httpStatus()`, validation `errors()` (path and message), `payload()`, `isConflict()`, `isNotFound()`, `isRateLimited()` with `retryAfter()` |
| `InvalidNotification` | a notification with a missing or wrong signature, of another merchant or malformed |
| `InvalidConfiguration` | missing credentials or URLs, unknown connection |

All extend `PayPoException`. Registration, confirmation, cancellation and refunds are never retried automatically;
the only resend happens after HTTP 401, when the cached token expired and the request was not processed. Status
reads are retried on connection errors and HTTP 5xx (`PAYPO_RETRIES`, `PAYPO_RETRY_DELAY`).

## Development

```bash
composer install
composer ci   # pint --test, phpstan (level 8), pest
```

The implementation follows the official "PayPo API v.3.1" specification (document version 3.1.2). PayPo issues
sandbox credentials to merchants; the test suite uses `Http::fake` with the examples from the specification.

## Author

Created and maintained by **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Issues: [GitHub issues](https://github.com/beziworld/laravel-paypo/issues)
- Security vulnerabilities: see [SECURITY.md](SECURITY.md)

Need an integration? Get in touch through [beziworld.eu](https://beziworld.eu).

### Other packages by the author

[laravel-payu](https://github.com/beziworld/laravel-payu) ·
[laravel-paynow](https://github.com/beziworld/laravel-paynow) ·
[laravel-przelewy24](https://github.com/beziworld/laravel-przelewy24) ·
[laravel-tpay](https://github.com/beziworld/laravel-tpay) ·
[laravel-imoje](https://github.com/beziworld/laravel-imoje)

## License

MIT. See [LICENSE.md](LICENSE.md). This package is not affiliated with PayPo sp. z o.o.

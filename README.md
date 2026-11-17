# Orboni for PHP

The official PHP library for the [Orboni API](https://orboni.com/docs): take crypto payments — USDT, USDC, BTC, ETH and more — paid straight to your own wallets. Orboni never holds your money.

- Every API call: checkout sessions, payment links, refunds, events, payment methods and currencies.
- Webhook signatures verified with PHP's own `hash_hmac`, compared in constant time; no API key needed for it.
- Safe retries: each POST carries an idempotency key, and network failures, `409`, `429` and `5xx` are tried again.
- No dependencies. PHP 8.1 or later, with the `curl` and `json` extensions.

## Install

```bash
composer require orboni/orboni-php
```

## Take a payment

Make a checkout session on your server and send the buyer to its `url`. Amounts are in the currency's smallest unit (cents, or whole yen).

```php
require __DIR__.'/vendor/autoload.php';

$orboni = new \Orboni\OrboniClient(getenv('ORBONI_SECRET_KEY')); // sk_test_… or sk_live_…

$session = $orboni->checkout->sessions->create(
    [
        'amount' => 2500,
        'currency' => 'usd',
        'client_reference_id' => 'order_1042',
        'success_url' => 'https://your-store.example/orders/1042',
        'cancel_url' => 'https://your-store.example/cart',
        'metadata' => ['order_id' => '1042'],
    ],
    ['idempotency_key' => 'order_1042'],
);

header('Location: '.$session->url, true, 303);
```

The buyer picks a coin on Orboni's payment page and pays from any wallet or exchange. Fulfil the order when the `checkout.session.completed` webhook arrives, or read the session back:

```php
$paid = $orboni->checkout->sessions->retrieve($session->id);
if ($paid->status === 'complete' && $paid->payment_status === 'paid') {
    // fulfil order 1042
}
```

## Answers

Each call answers an `Orboni\OrboniObject`: read its fields as properties or as array keys, nested objects alike. A field the answer does not have reads as `null`, and `isset()` works as on an array. Answers are read-only; `toArray()` gives plain arrays, and `json_encode()` gives the JSON back.

```php
$session->url;
$session['url'];
$session->metadata->order_id;
$session->payment?->coin; // payment is null until the buyer picks a coin
$session->toArray();
```

## Webhooks

Orboni signs every webhook with your endpoint's signing secret (`whsec_…`, under Developers → Webhooks in the dashboard). Verify it against the **raw** body, before anything decodes it:

```php
use Orboni\Exception\SignatureVerificationException;
use Orboni\Webhook;

$payload = file_get_contents('php://input');
$header = $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? null;

try {
    $event = Webhook::constructEvent($payload, $header, getenv('ORBONI_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit($e->getMessage());
}

if ($event->type === 'checkout.session.completed') {
    $session = $event->data->object; // fulfil $session->client_reference_id once
}
http_response_code(200);
```

In Laravel, pass `$request->getContent()` and `$request->header('Webhook-Signature')`, and leave the route out of CSRF protection (a route in `routes/api.php` has none):

```php
use Illuminate\Http\Request;
use Orboni\Exception\SignatureVerificationException;
use Orboni\Webhook;

Route::post('/webhooks/orboni', function (Request $request) {
    try {
        $event = Webhook::constructEvent($request->getContent(), $request->header('Webhook-Signature'), config('services.orboni.webhook_secret'));
    } catch (SignatureVerificationException $e) {
        return response($e->getMessage(), 400);
    }

    if ($event->type === 'checkout.session.completed') {
        $session = $event->data->object; // fulfil $session->client_reference_id once
    }

    return response()->noContent();
});
```

The same event can arrive more than once and out of order: keep the `id`s you have handled. In production, ignore events whose `livemode` is `false`. While you roll a signing secret, pass both: `Webhook::constructEvent($payload, $header, [$newSecret, $oldSecret])`. `$orboni->webhooks->constructEvent(…)` does the same from a client. The payload must be a string: an array your framework decoded is refused, since the signature covers the bytes as sent.

## Lists

A list call answers one page, an `Orboni\Collection`; `foreach` over it walks that page. `autoPagingIterator()` walks every object across the pages, asking for each next page as it goes:

```php
$page = $orboni->checkout->sessions->list(['limit' => 10, 'status' => 'complete']);
foreach ($page as $session) {
    echo $session->id, PHP_EOL;
}
$page->has_more;

foreach ($orboni->refunds->list(['checkout_session' => 'cs_live_…'])->autoPagingIterator() as $refund) {
    echo $refund->id, ' ', $refund->amount, PHP_EOL;
}

$recent = $orboni->events->list(['created' => ['gte' => 1767225600]])->autoPagingToArray(500);
```

A list asked with `ending_before` walks backwards from there, each page's objects oldest first.

## Everything else

```php
$orboni->checkout->sessions->expire('cs_live_…'); // an order cancelled on your side

$link = $orboni->paymentLinks->create(['amount' => 1500, 'currency' => 'eur', 'description' => 'Workshop ticket']);
$orboni->paymentLinks->update($link->id, ['active' => false]);

// Orboni holds no money: send the refund from your wallet, then record it
$orboni->refunds->create(['checkout_session' => 'cs_live_…', 'amount' => 1000, 'reason' => 'requested_by_customer', 'transaction' => '0x…']);

$orboni->events->resend('evt_…');
$methods = $orboni->paymentMethods->list(['amount' => 2500, 'currency' => 'usd']);
$currencies = $orboni->currencies->list();
```

The parameters and fields of each call are in the [API reference](https://orboni.com/docs). Parameters are the API's own names, sent as they are.

## Errors

A refused call throws an `Orboni\Exception\OrboniException` subclass named after the API's error `type`. Branch on the class or on `getType()`, never on the message:

| Class | `type` | When |
| --- | --- | --- |
| `InvalidRequestException` | `invalid_request_error` | A bad parameter (`getParam()` names it), or no such object (`getHttpStatus()` 404) |
| `AmountTooSmallException` | `amount_too_small` | Under the minimum of every network the session could be paid on |
| `IdempotencyException` | `idempotency_error` | An idempotency key reused with other parameters |
| `AuthenticationException` | `authentication_error` | No key, a wrong one or a revoked one |
| `PermissionException` | `permission_error` | A suspended or closed account, or a live key before the email is confirmed |
| `ReceivingFrozenException` | `receiving_frozen` | Receiving is frozen: live keys only read |
| `AccountPastDueException` | `account_past_due` | An Orboni invoice is past due (live only) |
| `RateLimitException` | `rate_limit_error` | Too many requests; `getRetryAfter()` says how many seconds to wait |
| `RateUnavailableException` | `rate_unavailable` | No current exchange rate for the currency |
| `ApiException` | `api_error` | Orboni's side; safe to retry with the same idempotency key |
| `ConnectionException` | `connection_error` | No answer: the network failed or the call timed out |

Every one also has `getMessage()`, `getHttpStatus()`, `getHeaders()` (lower-cased names) and `getError()` (the error object as the API sent it). A mistake caught before any request — an empty key or ID, an unknown option — is an `\InvalidArgumentException`.

```php
use Orboni\Exception\AmountTooSmallException;
use Orboni\Exception\InvalidRequestException;

try {
    $orboni->checkout->sessions->create(['amount' => 50, 'currency' => 'usd']);
} catch (AmountTooSmallException $e) {
    // offer another way to pay
} catch (InvalidRequestException $e) {
    echo $e->getParam(), ': ', $e->getMessage();
}
```

## Configuration

```php
$orboni = new \Orboni\OrboniClient(getenv('ORBONI_SECRET_KEY'), [
    'timeout' => 30, // seconds per try
    'max_retries' => 2, // after the first try
]);

// per call
$orboni->checkout->sessions->retrieve('cs_live_…', ['timeout' => 5, 'max_retries' => 0]);
```

Without `idempotency_key` the library makes one for each POST, so its own retries never create a second session or refund. Pass your own (an order ID is a good one) to make retries of your own safe too: Orboni keeps a successful answer for 24 hours and gives it again for the same key.

## Test mode

A test key (`sk_test_…`) makes test sessions that take no real money. Open a test session's payment page while signed in to the dashboard and press **Simulate payment** to see the whole flow, webhooks included. `$orboni->isTestMode()` says which kind of key a client has. See [Testing](https://orboni.com/docs/testing).

## License

MIT

<?php

declare(strict_types=1);

use Beziworld\PayPo\Enums\SettlementStatus;
use Beziworld\PayPo\Enums\TransactionStatus;
use Beziworld\PayPo\Events\SettlementReported;
use Beziworld\PayPo\Events\TransactionAccepted;
use Beziworld\PayPo\Events\TransactionCanceled;
use Beziworld\PayPo\Events\TransactionCompleted;
use Beziworld\PayPo\Events\TransactionRejected;
use Beziworld\PayPo\Events\TransactionStatusChanged;
use Beziworld\PayPo\Facades\PayPo;
use Beziworld\PayPo\Support\Signature;
use Beziworld\PayPo\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Posts a raw notification body to the package route, signed with the given key.
 */
function postPayPoNotification(TestCase $test, string $body, ?string $key = TestCase::MERCHANT_API_KEY, string $path = '/paypo/notification/default', ?string $signature = null): TestResponse
{
    $headers = ['CONTENT_TYPE' => 'application/json'];
    $signature ??= $key !== null ? Signature::compute('POST', $path, $body, $key) : null;

    if ($signature !== null) {
        $headers['HTTP_X_PAYPO_SIGNATURE'] = $signature;
    }

    return $test->call('POST', $path, [], [], [], $headers, $body);
}

function fakePayPoEvents(): void
{
    Event::fake([
        TransactionStatusChanged::class,
        TransactionAccepted::class,
        TransactionCompleted::class,
        TransactionRejected::class,
        TransactionCanceled::class,
        SettlementReported::class,
    ]);
}

function statusBody(string $status): string
{
    $data = paypoFixture('notification-status');
    $data['transactionStatus'] = $status;

    return json_encode($data, JSON_UNESCAPED_SLASHES);
}

it('dispatches status events for an authentic notification', function (): void {
    fakePayPoEvents();

    postPayPoNotification($this, paypoRawFixture('notification-status'))->assertOk();

    Event::assertDispatched(TransactionStatusChanged::class, fn(TransactionStatusChanged $event): bool => $event->connection === 'default'
        && $event->notification->status === TransactionStatus::Accepted
        && $event->notification->transactionId === '5909da74-af95-41e9-b8e2-12e61c3c6f27'
        && $event->notification->referenceId === 'ord_987654'
        && $event->notification->amount === 24900
        && $event->notification->transactionUrl === 'https://process.paypo.pl/5909da74-af95-41e9-b8e2-12e61c3c6f27'
        && $event->notification->lastUpdate?->toIso8601String() === '2020-03-05T10:54:02+01:00');
    Event::assertDispatched(TransactionAccepted::class);
    Event::assertNotDispatched(TransactionCompleted::class);
});

it('dispatches the event matching each status', function (string $status, string $event): void {
    fakePayPoEvents();

    postPayPoNotification($this, statusBody($status))->assertOk();

    Event::assertDispatched($event);
    Event::assertDispatched(TransactionStatusChanged::class);
})->with([
    'completed' => ['COMPLETED', TransactionCompleted::class],
    'rejected' => ['REJECTED', TransactionRejected::class],
    'canceled' => ['CANCELED', TransactionCanceled::class],
]);

it('dispatches only the status change event for PENDING', function (): void {
    fakePayPoEvents();

    postPayPoNotification($this, statusBody('PENDING'))->assertOk();

    Event::assertDispatchedTimes(TransactionStatusChanged::class, 1);
    Event::assertNotDispatched(TransactionAccepted::class);
    Event::assertNotDispatched(TransactionCompleted::class);
});

it('dispatches a settlement event for the wire transfer specification notification', function (): void {
    fakePayPoEvents();

    postPayPoNotification($this, paypoRawFixture('notification-settlement'))->assertOk();

    Event::assertDispatched(SettlementReported::class, fn(SettlementReported $event): bool => $event->notification->isSettlement()
        && $event->notification->settlementStatus === SettlementStatus::Paid
        && $event->notification->message === 'Transaction is settled'
        && $event->notification->shopId === 'c8847732-b5d7-4528-8a7b-54ede5e43789'
        && $event->notification->amountDecimal() === '12.12');
    Event::assertNotDispatched(TransactionCompleted::class);
});

it('rejects a notification with an invalid signature', function (): void {
    fakePayPoEvents();

    postPayPoNotification($this, paypoRawFixture('notification-status'), signature: 'bm90LWEtc2lnbmF0dXJl')->assertStatus(400);

    Event::assertNothingDispatched();
});

it('rejects a notification without a signature', function (): void {
    fakePayPoEvents();

    postPayPoNotification($this, paypoRawFixture('notification-status'), key: null)->assertStatus(400);

    Event::assertNothingDispatched();
});

it('rejects a notification signed with the key of another connection', function (): void {
    fakePayPoEvents();

    postPayPoNotification($this, paypoRawFixture('notification-status'), key: 'second-merchant-key')->assertStatus(400);

    Event::assertNothingDispatched();
});

it('rejects a notification of another merchant', function (): void {
    fakePayPoEvents();
    $data = paypoFixture('notification-status');
    $data['merchantId'] = '00000000-0000-0000-0000-000000000000';

    postPayPoNotification($this, json_encode($data, JSON_UNESCAPED_SLASHES))->assertStatus(400);

    Event::assertNothingDispatched();
});

it('rejects malformed bodies and unknown statuses', function (string $body): void {
    fakePayPoEvents();

    postPayPoNotification($this, $body)->assertStatus(400);

    Event::assertNothingDispatched();
})->with([
    'not json' => ['not json'],
    'unknown status' => [statusBody('SOMETHING')],
    'no transaction id' => ['{"transactionStatus":"ACCEPTED"}'],
]);

it('rejects notifications for unknown connections', function (): void {
    fakePayPoEvents();

    postPayPoNotification($this, paypoRawFixture('notification-status'), path: '/paypo/notification/unknown')->assertStatus(400);

    Event::assertNothingDispatched();
});

it('verifies notifications of another configured connection with its own key', function (): void {
    fakePayPoEvents();

    postPayPoNotification($this, paypoRawFixture('notification-status'), key: 'second-merchant-key', path: '/paypo/notification/second')->assertOk();

    Event::assertDispatched(TransactionAccepted::class, fn(TransactionAccepted $event): bool => $event->connection === 'second');
});

it('accepts the signature computed over the canonical re-encoded JSON', function (): void {
    fakePayPoEvents();
    $body = "{\n  \"merchantId\": \"19c692be-a893-468c-a65f-b8de442e5443\",\n  \"transactionId\": \"t-1\",\n  \"transactionStatus\": \"COMPLETED\",\n  \"transactionUrl\": \"https://process.paypo.pl/t-1\",\n  \"amount\": 100\n}";
    $canonical = Signature::canonicalJson(json_decode($body, true));

    postPayPoNotification($this, $body, signature: Signature::compute('POST', '/paypo/notification/default', $canonical, TestCase::MERCHANT_API_KEY))->assertOk();

    Event::assertDispatched(TransactionCompleted::class);
});

it('resolves runtime connections registered with a resolver', function (): void {
    fakePayPoEvents();
    PayPo::resolveConnectionsUsing(fn(string $name): ?array => $name === 'tenant-42'
        ? ['client_id' => 'tenant-client', 'client_secret' => 'tenant-secret', 'merchant_api_key' => 'tenant-key']
        : null);

    postPayPoNotification($this, paypoRawFixture('notification-status'), key: 'tenant-key', path: '/paypo/notification/tenant-42')->assertOk();

    Event::assertDispatched(TransactionAccepted::class, fn(TransactionAccepted $event): bool => $event->connection === 'tenant-42');
    expect(PayPo::connection('tenant-42')->notifyUrl())->toBe('http://localhost/paypo/notification/tenant-42');
});

it('accepts unsigned notifications only when verification is disabled', function (): void {
    fakePayPoEvents();
    config()->set('paypo.notifications.verify_signature', false);

    postPayPoNotification($this, paypoRawFixture('notification-status'), key: null)->assertOk();

    Event::assertDispatched(TransactionAccepted::class);
});

it('rejects notifications when the merchant API key is missing', function (): void {
    fakePayPoEvents();
    config()->set('paypo.connections.default.merchant_api_key', null);

    postPayPoNotification($this, paypoRawFixture('notification-status'))->assertStatus(400);

    Event::assertNothingDispatched();
});

it('confirms the status through the API when verify_status is enabled', function (): void {
    fakePayPoEvents();
    config()->set('paypo.notifications.verify_status', true);
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => Http::response(array_merge(paypoFixture('transaction-extended'), [
            'transactionId' => '5909da74-af95-41e9-b8e2-12e61c3c6f27',
            'transactionStatus' => 'COMPLETED',
        ])),
    ]);

    postPayPoNotification($this, paypoRawFixture('notification-status'))->assertOk();

    Event::assertDispatched(TransactionCompleted::class, fn(TransactionCompleted $event): bool => $event->notification->settlementStatus === SettlementStatus::Paid);
    Event::assertNotDispatched(TransactionAccepted::class);
    Http::assertSent(fn($request): bool => $request->url() === TestCase::SANDBOX . '/transactions/5909da74-af95-41e9-b8e2-12e61c3c6f27');
});

it('asks PayPo to retry when the status cannot be confirmed', function (): void {
    fakePayPoEvents();
    config()->set('paypo.notifications.verify_status', true);
    config()->set('paypo.retries', 0);
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => Http::response(['code' => 503], 503),
    ]);

    postPayPoNotification($this, paypoRawFixture('notification-status'))->assertStatus(503);

    Event::assertNothingDispatched();
});

it('parses and verifies notifications in a custom controller', function (): void {
    $body = paypoRawFixture('notification-status');
    $signature = Signature::compute('POST', '/webhooks/paypo', $body, TestCase::MERCHANT_API_KEY);

    $notification = PayPo::parseNotification($body, $signature, '/webhooks/paypo');

    expect($notification->status)->toBe(TransactionStatus::Accepted)
        ->and($notification->isSettlement())->toBeFalse();
});

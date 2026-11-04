<?php

declare(strict_types=1);

use Beziworld\PayPo\Data\Address;
use Beziworld\PayPo\Data\Customer;
use Beziworld\PayPo\Data\TransactionRequest;
use Beziworld\PayPo\Enums\SettlementStatus;
use Beziworld\PayPo\Enums\Shipment;
use Beziworld\PayPo\Enums\TransactionStatus;
use Beziworld\PayPo\Exceptions\ApiException;
use Beziworld\PayPo\Exceptions\InvalidConfiguration;
use Beziworld\PayPo\Facades\PayPo;
use Beziworld\PayPo\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function paypoRequest(array $overrides = []): TransactionRequest
{
    return new TransactionRequest(...array_merge([
        'referenceId' => 'ord_98765/20',
        'amount' => 24900,
        'customer' => new Customer('Anna', 'Nowak', 'anna.n@paypo.pl', '+48500123456'),
        'billingAddress' => new Address('Kredytowa', '9a', 'Warszawa', '00-950', '3', 'mazowieckie'),
        'shippingAddress' => new Address('Domaniewska', '39', 'Warszawa', '02-672'),
        'description' => 'test',
        'shipment' => Shipment::Courier,
    ], $overrides));
}

it('registers a transaction with an OAuth token and returns the redirect URL', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions' => Http::response([
            'transactionId' => '5909da74-af95-41e9-b8e2-12e61c3c6f27',
            'redirectUrl' => 'https://process.paypo.pl/5909da74-af95-41e9-b8e2-12e61c3c6f27',
        ], 201),
    ]);

    $created = PayPo::createTransaction(paypoRequest());

    expect($created->transactionId)->toBe('5909da74-af95-41e9-b8e2-12e61c3c6f27')
        ->and($created->redirectUrl)->toBe('https://process.paypo.pl/5909da74-af95-41e9-b8e2-12e61c3c6f27');

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::SANDBOX . '/oauth/tokens'
        && $request->method() === 'POST'
        && $request->isForm()
        && ($request->data()['grant_type'] ?? null) === 'client_credentials'
        && ($request->data()['client_id'] ?? null) === TestCase::CLIENT_ID
        && ($request->data()['client_secret'] ?? null) === TestCase::CLIENT_SECRET);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== TestCase::SANDBOX . '/transactions') {
            return false;
        }

        $body = $request->data();

        return $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer access-token-1')
            && $request->isJson()
            && $body['order']['referenceId'] === 'ord_98765/20'
            && $body['order']['amount'] === 24900
            && $body['order']['shipment'] === 0
            && $body['order']['billingAddress'] === ['street' => 'Kredytowa', 'building' => '9a', 'flat' => '3', 'zip' => '00-950', 'city' => 'Warszawa', 'county' => 'mazowieckie', 'country' => 'PL']
            && $body['order']['shippingAddress']['street'] === 'Domaniewska'
            && $body['customer'] === ['name' => 'Anna', 'surname' => 'Nowak', 'email' => 'anna.n@paypo.pl', 'phone' => '+48500123456']
            && $body['configuration']['returnUrl'] === 'https://shop.example.com/paypo/return'
            && $body['configuration']['notifyUrl'] === 'http://localhost/paypo/notification/default'
            && ! array_key_exists('cancelUrl', $body['configuration'])
            && ! array_key_exists('shopId', $body);
    });
});

it('uses the billing address for delivery and per-request URLs and identifiers', function (): void {
    config()->set('paypo.connections.default.shop_id', '088fa21e-efab-4ecb-9022-a15cc8344ccd');
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions' => Http::response(['transactionId' => 'abc', 'redirectUrl' => 'https://process.paypo.pl/abc'], 201),
    ]);

    PayPo::createTransaction(paypoRequest([
        'shippingAddress' => null,
        'shipment' => Shipment::ParcelLocker,
        'id' => '5c1b82ab-6c9a-4b4e-a892-ce3a7dc1396f',
        'returnUrl' => 'https://shop.example.com/r',
        'notifyUrl' => 'https://shop.example.com/n',
        'cancelUrl' => 'https://shop.example.com/c',
        'customer' => new Customer('Anna', 'Nowak', 'anna@example.com', isRegistered: true, registeredAt: new DateTimeImmutable('2022-01-01'), numberOfTransactions: 1, sumOfTransactions: 123456),
    ]));

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === TestCase::SANDBOX . '/transactions'
            && $body['id'] === '5c1b82ab-6c9a-4b4e-a892-ce3a7dc1396f'
            && $body['shopId'] === '088fa21e-efab-4ecb-9022-a15cc8344ccd'
            && $body['order']['shipment'] === 2
            && $body['order']['shippingAddress'] === $body['order']['billingAddress']
            && $body['configuration'] === ['returnUrl' => 'https://shop.example.com/r', 'notifyUrl' => 'https://shop.example.com/n', 'cancelUrl' => 'https://shop.example.com/c']
            && $body['customer']['registrationInfo'] === ['isRegistered' => true, 'dateOfRegistration' => '2022-01-01']
            && $body['customer']['transactionsInfo'] === ['numberOfTransactions' => 1, 'sumOfTransactions' => 123456];
    });
});

it('requires a return URL', function (): void {
    config()->set('paypo.return_url', null);
    Http::fake();

    PayPo::createTransaction(paypoRequest());
})->throws(InvalidConfiguration::class, 'returnUrl');

it('rejects non-positive amounts and empty references', function (): void {
    expect(fn() => paypoRequest(['amount' => 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn() => paypoRequest(['referenceId' => ' ']))->toThrow(InvalidArgumentException::class);
});

it('reads the extended transaction status with refunds', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => Http::response(paypoFixture('transaction-extended')),
    ]);

    $transaction = PayPo::transaction('cd975bc6-a755-4141-b7a0-d7e8f7a308ef');

    expect($transaction->status)->toBe(TransactionStatus::Completed)
        ->and($transaction->settlementStatus)->toBe(SettlementStatus::Paid)
        ->and($transaction->amount)->toBe(24900)
        ->and($transaction->amountDecimal())->toBe('249.00')
        ->and($transaction->referenceId)->toBe('ord_987654')
        ->and($transaction->lastUpdate?->toIso8601String())->toBe('2020-03-05T10:54:02+01:00')
        ->and($transaction->refunds)->toHaveCount(2)
        ->and($transaction->refundedAmount())->toBe(9510)
        ->and($transaction->findRefund('3e12a361-d193-4f3a-88b5-b8fda405a529')?->amountDecimal())->toBe('86.55')
        ->and($transaction->refunds[1]->referenceRefundId)->toBeNull()
        ->and($transaction->refunds[0]->created?->format('Y-m-d H:i:s'))->toBe('2023-09-08 13:46:04');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'GET'
        && $request->url() === TestCase::SANDBOX . '/transactions/cd975bc6-a755-4141-b7a0-d7e8f7a308ef'
        && $request->hasHeader('Authorization', 'Bearer access-token-1'));
});

it('distinguishes a disabled extended response from an empty refund list', function (): void {
    $data = paypoFixture('transaction-extended');
    unset($data['refunds']);
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => Http::response($data),
    ]);

    $transaction = PayPo::transaction('cd975bc6-a755-4141-b7a0-d7e8f7a308ef');

    expect($transaction->refunds)->toBeNull()->and($transaction->refundedAmount())->toBeNull();
});

it('confirms a transaction with PATCH status COMPLETED', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => Http::response(['code' => 200, 'message' => 'Transaction updated successfully']),
    ]);

    $result = PayPo::confirm('cd975bc6-a755-4141-b7a0-d7e8f7a308ef');

    expect($result->code)->toBe(200)->and($result->message)->toBe('Transaction updated successfully');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === TestCase::SANDBOX . '/transactions/cd975bc6-a755-4141-b7a0-d7e8f7a308ef'
        && $request->data() === ['status' => 'COMPLETED']);
});

it('cancels a transaction with PATCH status CANCELED', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => Http::response(['code' => 201, 'message' => 'Transaction updated successfully'], 201),
    ]);

    expect(PayPo::cancel('cd975bc6')->code)->toBe(201);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PATCH' && $request->data() === ['status' => 'CANCELED']);
});

it('reports a conflict when cancelling a completed transaction', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => Http::response(['code' => 409, 'message' => 'Conflict'], 409),
    ]);

    try {
        PayPo::cancel('cd975bc6');
        $this->fail('Expected exception.');
    } catch (ApiException $exception) {
        expect($exception->isConflict())->toBeTrue()->and($exception->httpStatus())->toBe(409);
    }

    Http::assertSentCount(2);
});

it('registers refunds with a reference id', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*/refunds' => Http::response(['code' => 201, 'message' => 'Refund created successfully'], 201),
    ]);

    $result = PayPo::refund('cd975bc6-a755-4141-b7a0-d7e8f7a308ef', 8655, 'refund-1');

    expect($result->code)->toBe(201)->and($result->message)->toBe('Refund created successfully');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->url() === TestCase::SANDBOX . '/transactions/cd975bc6-a755-4141-b7a0-d7e8f7a308ef/refunds'
        && $request->data() === ['amount' => 8655, 'referenceRefundId' => 'refund-1']);
});

it('validates refund arguments before calling the API', function (): void {
    Http::fake();

    expect(fn() => PayPo::refund('abc', 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => PayPo::refund('abc', 100, str_repeat('x', 69)))->toThrow(InvalidArgumentException::class)
        ->and(fn() => PayPo::refund(' ', 100))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('exposes validation errors of HTTP 400 responses', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions' => Http::response([
            'code' => 400,
            'message' => 'Bad request',
            'errors' => [['path' => 'order.referenceId', 'message' => 'This value should not be blank.']],
        ], 400),
    ]);

    try {
        PayPo::createTransaction(paypoRequest());
        $this->fail('Expected exception.');
    } catch (ApiException $exception) {
        expect($exception->httpStatus())->toBe(400)
            ->and($exception->errors())->toBe([['path' => 'order.referenceId', 'message' => 'This value should not be blank.']])
            ->and($exception->getMessage())->toContain('order.referenceId: This value should not be blank.');
    }
});

it('reports refund errors without validation details', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*/refunds' => Http::response(['code' => 400, 'message' => 'Refund amount 30000 can not be greater than order amount 24900.'], 400),
    ]);

    PayPo::refund('abc', 30000);
})->throws(ApiException::class, 'can not be greater than order amount');

it('never retries a failed write', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions' => Http::response(['code' => 503, 'message' => 'Service Unavailable'], 503),
    ]);

    expect(fn() => PayPo::createTransaction(paypoRequest()))->toThrow(ApiException::class, 'Service Unavailable');
    Http::assertSentCount(2);
});

it('retries status reads on server errors', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => Http::sequence()
            ->push(['code' => 503, 'message' => 'Service Unavailable'], 503)
            ->push(paypoFixture('transaction-extended')),
    ]);

    expect(PayPo::transaction('cd975bc6')->status)->toBe(TransactionStatus::Completed);
    Http::assertSentCount(3);
});

it('retries status reads on connection errors and gives up after the configured attempts', function (): void {
    $attempts = 0;
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('timeout');
        },
    ]);

    expect(fn() => PayPo::transaction('cd975bc6'))->toThrow(ApiException::class, 'Could not connect to PayPo')
        ->and($attempts)->toBe(3);
});

it('reports rate limiting with Retry-After', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => Http::response([], 429, ['Retry-After' => '30']),
    ]);

    try {
        PayPo::transaction('cd975bc6');
        $this->fail('Expected exception.');
    } catch (ApiException $exception) {
        expect($exception->isRateLimited())->toBeTrue()->and($exception->retryAfter())->toBe(30);
    }
});

it('caches the OAuth token between calls', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response(paypoTokenResponse()),
        TestCase::SANDBOX . '/transactions/*' => Http::response(paypoFixture('transaction-extended')),
    ]);

    PayPo::transaction('a');
    PayPo::transaction('b');

    Http::assertSentCount(3);
});

it('refreshes an expired token once after HTTP 401', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::sequence()
            ->push(paypoTokenResponse('old-token'))
            ->push(paypoTokenResponse('new-token')),
        TestCase::SANDBOX . '/transactions/*' => Http::sequence()
            ->push(['code' => 401, 'message' => 'Unauthorized', 'messageKey' => null, 'uuid' => null], 401)
            ->push(['code' => 200, 'message' => 'Transaction updated successfully']),
    ]);

    expect(PayPo::confirm('abc')->code)->toBe(200);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PATCH' && $request->hasHeader('Authorization', 'Bearer new-token'));
});

it('reports rejected client credentials', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'Client authentication failed',
            'message' => 'Client authentication failed',
        ], 401),
    ]);

    try {
        PayPo::transaction('abc');
        $this->fail('Expected exception.');
    } catch (ApiException $exception) {
        expect($exception->httpStatus())->toBe(401)
            ->and($exception->getMessage())->toContain('OAuth client credentials')
            ->and($exception->payload()['error'] ?? null)->toBe('invalid_client');
    }
});

it('uses the production host when the sandbox is disabled and a custom base URL when set', function (): void {
    config()->set('paypo.sandbox', false);
    Http::fake([
        'https://api.paypo.pl/v3/oauth/tokens' => Http::response(paypoTokenResponse()),
        'https://api.paypo.pl/v3/transactions/*' => Http::response(paypoFixture('transaction-extended')),
        'https://api.paypo.ro/v3/oauth/tokens' => Http::response(paypoTokenResponse('ro-token')),
        'https://api.paypo.ro/v3/transactions/*' => Http::response(paypoFixture('transaction-extended')),
    ]);

    PayPo::transaction('abc');
    PayPo::using(['client_id' => 'ro', 'client_secret' => 'ro-secret', 'base_url' => 'https://api.paypo.ro/v3/', 'currency' => 'RON'])->transaction('abc');

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://api.paypo.pl/v3/transactions/abc');
    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://api.paypo.ro/v3/transactions/abc' && $request->hasHeader('Authorization', 'Bearer ro-token'));
});

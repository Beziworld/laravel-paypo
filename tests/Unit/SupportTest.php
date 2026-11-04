<?php

declare(strict_types=1);

use Beziworld\PayPo\Enums\TransactionStatus;
use Beziworld\PayPo\Exceptions\ApiException;
use Beziworld\PayPo\Exceptions\InvalidConfiguration;
use Beziworld\PayPo\Exceptions\InvalidNotification;
use Beziworld\PayPo\PayPoConfig;
use Beziworld\PayPo\Support\Amount;
use Beziworld\PayPo\Support\Signature;

it('computes the documented HMAC-SHA256 base64 signature', function (): void {
    // Reference value computed independently with Python hmac/hashlib.
    expect(Signature::compute('POST', '/notifyUrl', '{"transactionId":"00102030","transactionStatus":"PENDING"}', 'merchant-api-key'))
        ->toBe('G6rMI64l7M8ijs1H61YPEV08aL7qZGx33mKqLy5Ppzc=');
});

it('verifies signatures against any candidate path', function (): void {
    $body = '{"a":"b/c"}';
    $signature = Signature::compute('POST', '/app/paypo/notification', $body, 'key');

    Signature::verify($body, $signature, ['/paypo/notification', '/app/paypo/notification'], 'key');

    expect(fn() => Signature::verify($body, $signature, ['/paypo/notification'], 'key'))->toThrow(InvalidNotification::class)
        ->and(fn() => Signature::verify($body, ' ', ['/app/paypo/notification'], 'key'))->toThrow(InvalidNotification::class, 'no X-PayPo-Signature');
});

it('encodes canonical JSON without escaping slashes or unicode', function (): void {
    expect(Signature::canonicalJson(['url' => 'https://x.pl/a', 'city' => 'Łódź']))->toBe('{"url":"https://x.pl/a","city":"Łódź"}');
});

it('converts between decimal amounts and minor units', function (): void {
    expect(Amount::toMinor('249.90'))->toBe(24990)
        ->and(Amount::toMinor('249,9'))->toBe(24990)
        ->and(Amount::toMinor('1 249'))->toBe(124900)
        ->and(Amount::toMinor(5))->toBe(500)
        ->and(Amount::toMinor('-0.05'))->toBe(-5)
        ->and(Amount::toDecimal(24990))->toBe('249.90')
        ->and(Amount::toDecimal(5))->toBe('0.05')
        ->and(Amount::toDecimal(-1205))->toBe('-12.05')
        ->and(fn() => Amount::toMinor('12.345'))->toThrow(InvalidArgumentException::class);
});

it('describes the status hierarchy', function (): void {
    expect(TransactionStatus::Completed->rank())->toBeGreaterThan(TransactionStatus::Accepted->rank())
        ->and(TransactionStatus::Accepted->isAccepted())->toBeTrue()
        ->and(TransactionStatus::Accepted->isRefundable())->toBeTrue()
        ->and(TransactionStatus::Pending->isRefundable())->toBeFalse()
        ->and(TransactionStatus::Completed->isCancelable())->toBeFalse()
        ->and(TransactionStatus::Rejected->isCancelable())->toBeTrue()
        ->and(TransactionStatus::Rejected->isFinal())->toBeFalse()
        ->and(TransactionStatus::Canceled->isFinal())->toBeTrue();
});

it('builds the configuration with defaults and validates credentials', function (): void {
    $config = PayPoConfig::fromArray(['client_id' => 'id', 'client_secret' => 'secret', 'sandbox' => 'false', 'timeout' => '0', 'currency' => 'ron']);

    expect($config->apiUrl())->toBe(PayPoConfig::PRODUCTION_URL)
        ->and($config->timeout)->toBe(20)
        ->and($config->currency)->toBe('RON')
        ->and($config->retries)->toBe(2)
        ->and(PayPoConfig::fromArray(['client_id' => 'id', 'client_secret' => 's'])->apiUrl())->toBe(PayPoConfig::SANDBOX_URL)
        ->and(fn() => PayPoConfig::fromArray(['client_secret' => 's']))->toThrow(InvalidConfiguration::class, 'PAYPO_CLIENT_ID')
        ->and(fn() => PayPoConfig::fromArray(['client_id' => 'id']))->toThrow(InvalidConfiguration::class, 'PAYPO_CLIENT_SECRET');
});

it('reads error maps keyed by path', function (): void {
    $exception = ApiException::fromResponse(['code' => 400, 'message' => 'Bad request', 'errors' => ['billingAddress.zip' => 'Missing mandatory parameter']], 400);

    expect($exception->errors())->toBe([['path' => 'billingAddress.zip', 'message' => 'Missing mandatory parameter']])
        ->and($exception->getMessage())->toBe('PayPo error (HTTP 400): Bad request (billingAddress.zip: Missing mandatory parameter)');
});

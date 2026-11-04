<?php

declare(strict_types=1);

use Beziworld\PayPo\Exceptions\InvalidConfiguration;
use Beziworld\PayPo\Facades\PayPo;
use Beziworld\PayPo\PayPoClient;
use Beziworld\PayPo\PayPoConfig;
use Beziworld\PayPo\PayPoManager;
use Beziworld\PayPo\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('resolves the default client from the container', function (): void {
    $client = app(PayPoClient::class);

    expect($client->connectionName())->toBe('default')
        ->and(app(PayPoConfig::class)->clientId)->toBe(TestCase::CLIENT_ID)
        ->and(app(PayPoManager::class)->connection())->toBe(app(PayPoManager::class)->connection('default'));
});

it('lists connections and uses separate tokens per merchant', function (): void {
    Http::fake([
        TestCase::SANDBOX . '/oauth/tokens' => function (Request $request) {
            return Http::response(paypoTokenResponse('token-' . $request->data()['client_id']));
        },
        TestCase::SANDBOX . '/transactions/*' => Http::response(paypoFixture('transaction-extended')),
    ]);

    PayPo::connection()->transaction('a');
    PayPo::connection('second')->transaction('b');

    expect(PayPo::connectionNames())->toBe(['default', 'second']);
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/transactions/a') && $request->hasHeader('Authorization', 'Bearer token-' . TestCase::CLIENT_ID));
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/transactions/b') && $request->hasHeader('Authorization', 'Bearer token-second-client'));
});

it('builds the default notification URL per connection', function (): void {
    expect(PayPo::connection('second')->notifyUrl())->toBe('http://localhost/paypo/notification/second')
        ->and(PayPo::using(['client_id' => 'x', 'client_secret' => 'y'])->notifyUrl())->toBeNull()
        ->and(PayPo::using(['client_id' => 'x', 'client_secret' => 'y', 'name' => 'tenant-7'])->notifyUrl())->toBe('http://localhost/paypo/notification/tenant-7')
        ->and(PayPo::using(['client_id' => 'x', 'client_secret' => 'y', 'notify_url' => 'https://hooks.example.com/paypo'])->notifyUrl())->toBe('https://hooks.example.com/paypo');
});

it('merges runtime credentials with shared options', function (): void {
    config()->set('paypo.timeout', 45);

    $client = PayPo::using(['client_id' => 'tenant', 'client_secret' => 'secret', 'merchant_api_key' => 'key', 'return_url' => null]);

    expect($client->config()->timeout)->toBe(45)
        ->and($client->config()->returnUrl)->toBe('https://shop.example.com/paypo/return')
        ->and($client->config()->merchantApiKey)->toBe('key')
        ->and(PayPo::using(new PayPoConfig('a', 'b'))->config()->clientId)->toBe('a');
});

it('supports a single-account configuration without connections', function (): void {
    config()->set('paypo.connections', []);
    config()->set('paypo.client_id', 'flat-id');
    config()->set('paypo.client_secret', 'flat-secret');
    app(PayPoManager::class)->purge();

    expect(PayPo::connection()->config()->clientId)->toBe('flat-id')
        ->and(PayPo::connectionNames())->toBe(['default']);
});

it('fails for unknown or incomplete connections', function (): void {
    expect(fn() => PayPo::connection('missing'))->toThrow(InvalidConfiguration::class, '"missing" is not defined');

    config()->set('paypo.connections.broken', ['client_id' => 'only-id']);

    expect(fn() => PayPo::connection('broken'))->toThrow(InvalidConfiguration::class, 'PAYPO_CLIENT_SECRET');
});

it('forwards unknown methods to the default client and rejects missing ones', function (): void {
    expect(PayPo::connectionName())->toBe('default')
        ->and(fn() => app(PayPoManager::class)->doesNotExist())->toThrow(BadMethodCallException::class);
});

it('registers the notification route and does not send a notify URL when it is disabled', function (): void {
    expect(app('router')->getRoutes()->getByName('paypo.notification'))->not->toBeNull();

    config()->set('paypo.notifications.enabled', false);
    app(PayPoManager::class)->purge();

    expect(PayPo::connection()->notifyUrl())->toBeNull();
});

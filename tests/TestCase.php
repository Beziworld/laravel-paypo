<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Tests;

use Beziworld\PayPo\PayPoServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const string CLIENT_ID = 'test-client-id';

    public const string CLIENT_SECRET = 'test-client-secret';

    public const string MERCHANT_ID = '19c692be-a893-468c-a65f-b8de442e5443';

    public const string MERCHANT_API_KEY = 'test-merchant-api-key';

    public const string SANDBOX = 'https://api.sandbox.paypo.pl/v3';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [PayPoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://shop.example.com');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('paypo.connections.default', [
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'merchant_id' => self::MERCHANT_ID,
            'merchant_api_key' => self::MERCHANT_API_KEY,
        ]);
        $app['config']->set('paypo.connections.second', [
            'client_id' => 'second-client',
            'client_secret' => 'second-secret',
            'merchant_api_key' => 'second-merchant-key',
        ]);
        $app['config']->set('paypo.sandbox', true);
        $app['config']->set('paypo.retry_delay', 0);
        $app['config']->set('paypo.return_url', 'https://shop.example.com/paypo/return');
    }
}

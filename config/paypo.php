<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Every connection is one PayPo merchant account. The OAuth "client_id"
    | and "client_secret", the "merchant_id" and the "merchant_api_key"
    | (used to verify the X-PayPo-Signature header of notifications) are
    | available in the PayPo merchant panel. "shop_id" is optional and
    | identifies a separate point of sale of the same merchant.
    |
    | Use PayPo::connection('name') for another merchant and
    | PayPo::using([...]) for credentials stored at runtime, for example per
    | tenant. Options outside "connections" are shared by every connection
    | and can be overridden in a connection.
    |
    */

    'default' => env('PAYPO_CONNECTION', 'default'),

    'connections' => [

        'default' => [
            'client_id' => env('PAYPO_CLIENT_ID'),
            'client_secret' => env('PAYPO_CLIENT_SECRET'),
            'merchant_id' => env('PAYPO_MERCHANT_ID'),
            'merchant_api_key' => env('PAYPO_MERCHANT_API_KEY'),
            'shop_id' => env('PAYPO_SHOP_ID'),
        ],

        // 'second-shop' => [
        //     'client_id' => env('PAYPO_SECOND_CLIENT_ID'),
        //     'client_secret' => env('PAYPO_SECOND_CLIENT_SECRET'),
        //     'merchant_id' => env('PAYPO_SECOND_MERCHANT_ID'),
        //     'merchant_api_key' => env('PAYPO_SECOND_MERCHANT_API_KEY'),
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The sandbox uses https://api.sandbox.paypo.pl/v3, production
    | https://api.paypo.pl/v3. "base_url" overrides both, for example
    | https://api.paypo.ro/v3 for the Romanian market.
    |
    */

    'sandbox' => (bool) env('PAYPO_SANDBOX', true),

    'base_url' => env('PAYPO_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Defaults of new transactions
    |--------------------------------------------------------------------------
    |
    | "return_url" receives the buyer with ?status=OK or ?status=ERR,
    | "cancel_url" receives a buyer who resigned from PayPo (returnUrl is
    | used when it is empty). "notify_url" defaults to the package route of
    | the connection. "currency" only labels decimal amounts in the data
    | objects; PayPo itself works in minor units of the market currency.
    |
    */

    'return_url' => env('PAYPO_RETURN_URL'),

    'cancel_url' => env('PAYPO_CANCEL_URL'),

    'notify_url' => env('PAYPO_NOTIFY_URL'),

    'currency' => env('PAYPO_CURRENCY', 'PLN'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Reads (transaction status) are retried "retries" times on connection
    | errors and HTTP 5xx, waiting "retry_delay" milliseconds in between.
    | Writes (registration, confirmation, cancellation, refunds) are never
    | retried. OAuth tokens are cached in the default cache store under
    | "cache_prefix" until shortly before expiry.
    |
    */

    'timeout' => (int) env('PAYPO_TIMEOUT', 20),

    'retries' => (int) env('PAYPO_RETRIES', 2),

    'retry_delay' => (int) env('PAYPO_RETRY_DELAY', 300),

    'cache_prefix' => env('PAYPO_CACHE_PREFIX', 'paypo'),

    'timezone' => env('PAYPO_TIMEZONE', 'Europe/Warsaw'),

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Route receiving PayPo notifications: {path}/{connection?}. The
    | X-PayPo-Signature header is verified with the merchant API key of the
    | connection; notifications without a valid signature are rejected with
    | HTTP 400. With "verify_status" the transaction is also fetched from
    | the API and the events carry the status reported by PayPo itself.
    |
    */

    'notifications' => [
        'enabled' => (bool) env('PAYPO_NOTIFICATIONS_ENABLED', true),
        'path' => env('PAYPO_NOTIFICATION_PATH', 'paypo/notification'),
        'name' => 'paypo.notification',
        'middleware' => ['api'],
        'verify_signature' => (bool) env('PAYPO_VERIFY_SIGNATURE', true),
        'verify_status' => (bool) env('PAYPO_VERIFY_STATUS', false),
    ],

];

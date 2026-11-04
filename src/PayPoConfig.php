<?php

declare(strict_types=1);

namespace Beziworld\PayPo;

use Beziworld\PayPo\Exceptions\InvalidConfiguration;

/**
 * Validated, immutable configuration of one PayPo merchant account.
 */
final readonly class PayPoConfig
{
    public const string PRODUCTION_URL = 'https://api.paypo.pl/v3';

    public const string SANDBOX_URL = 'https://api.sandbox.paypo.pl/v3';

    /**
     * @param string|null $name Connection name; it is embedded in the default notification URL.
     */
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public ?string $merchantId = null,
        #[\SensitiveParameter]
        public ?string $merchantApiKey = null,
        public ?string $shopId = null,
        public bool $sandbox = true,
        public ?string $baseUrl = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public ?string $notifyUrl = null,
        public string $currency = 'PLN',
        public int $timeout = 20,
        public int $retries = 2,
        public int $retryDelay = 300,
        public string $cachePrefix = 'paypo',
        public string $timezone = 'Europe/Warsaw',
        public ?string $name = null,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, ?string $name = null): self
    {
        $currency = self::string($config['currency'] ?? null);

        return new self(
            clientId: self::string($config['client_id'] ?? null) ?? throw InvalidConfiguration::missing('client_id', 'PAYPO_CLIENT_ID'),
            clientSecret: self::string($config['client_secret'] ?? null) ?? throw InvalidConfiguration::missing('client_secret', 'PAYPO_CLIENT_SECRET'),
            merchantId: self::string($config['merchant_id'] ?? null),
            merchantApiKey: self::string($config['merchant_api_key'] ?? null),
            shopId: self::string($config['shop_id'] ?? null),
            sandbox: filter_var($config['sandbox'] ?? true, FILTER_VALIDATE_BOOL),
            baseUrl: self::string($config['base_url'] ?? null),
            returnUrl: self::string($config['return_url'] ?? null),
            cancelUrl: self::string($config['cancel_url'] ?? null),
            notifyUrl: self::string($config['notify_url'] ?? null),
            currency: $currency !== null ? strtoupper($currency) : 'PLN',
            timeout: self::positive($config['timeout'] ?? null, 20),
            retries: self::natural($config['retries'] ?? null, 2),
            retryDelay: self::natural($config['retry_delay'] ?? null, 300),
            cachePrefix: self::string($config['cache_prefix'] ?? null) ?? 'paypo',
            timezone: self::string($config['timezone'] ?? null) ?? 'Europe/Warsaw',
            name: self::string($config['name'] ?? null) ?? $name,
        );
    }

    /**
     * Base URL of the API without a trailing slash, e.g. "https://api.paypo.pl/v3".
     */
    public function apiUrl(): string
    {
        if ($this->baseUrl !== null) {
            return rtrim($this->baseUrl, '/');
        }

        return $this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    private static function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function positive(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private static function natural(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }
}

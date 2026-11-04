<?php

declare(strict_types=1);

namespace Beziworld\PayPo;

use Beziworld\PayPo\Auth\AccessTokenProvider;
use Beziworld\PayPo\Exceptions\InvalidConfiguration;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves PayPo clients for any number of merchant accounts.
 *
 * Named connections are defined in config/paypo.php in the same way as
 * database connections; options outside "connections" are shared defaults.
 * Clients for credentials stored at runtime (for example per tenant) are
 * created with using(). Runtime connections that receive notifications are
 * registered with resolveConnectionsUsing(), so the notification route can
 * find their merchant API key by name. Calls made directly on the manager
 * go to the default connection.
 *
 * @mixin PayPoClient
 */
final class PayPoManager
{
    /** @var array<string, PayPoClient> */
    private array $clients = [];

    /** @var (Closure(string): (PayPoConfig|array<string, mixed>|null))|null */
    private ?Closure $resolver = null;

    /**
     * @param (Closure(PayPoConfig): ?string)|null $notifyUrlResolver
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly ?Closure $notifyUrlResolver = null,
    ) {}

    /**
     * Client of a configured (or resolver-provided) connection; the default connection when no name is given.
     *
     * @throws InvalidConfiguration When the connection is not defined or incomplete.
     */
    public function connection(?string $name = null): PayPoClient
    {
        $name ??= $this->getDefaultConnection();

        return $this->clients[$name] ??= $this->make($this->configFor($name));
    }

    /**
     * Client for credentials provided at runtime, merged with the shared options.
     *
     * Give the array a "name" to receive notifications on the package route
     * under that name; the name must then be resolvable through
     * resolveConnectionsUsing().
     *
     * @param PayPoConfig|array<string, mixed> $config Keys as in a connection: client_id, client_secret, merchant_id, merchant_api_key, ...
     */
    public function using(PayPoConfig|array $config): PayPoClient
    {
        return $this->make($config instanceof PayPoConfig ? $config : PayPoConfig::fromArray(self::merge($this->shared(), $config)));
    }

    /**
     * Registers a resolver of connections that are not defined in the configuration file.
     *
     * The resolver receives the connection name (e.g. a tenant key taken from
     * the notification URL) and returns its configuration or null when unknown.
     *
     * @param Closure(string): (PayPoConfig|array<string, mixed>|null) $resolver
     */
    public function resolveConnectionsUsing(Closure $resolver): void
    {
        $this->resolver = $resolver;
        $this->clients = [];
    }

    public function getDefaultConnection(): string
    {
        $default = $this->config->get('paypo.default');

        return is_string($default) && $default !== '' ? $default : 'default';
    }

    /**
     * Names of the configured connections.
     *
     * @return list<string>
     */
    public function connectionNames(): array
    {
        $connections = $this->config->get('paypo.connections');

        if (! is_array($connections) || $connections === []) {
            return [$this->getDefaultConnection()];
        }

        return array_map(strval(...), array_keys($connections));
    }

    /**
     * Forgets resolved clients, for example after the configuration changed.
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->clients = [];

            return;
        }

        unset($this->clients[$name]);
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $callable = [$this->connection(), $method];

        if (! is_callable($callable)) {
            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', PayPoClient::class, $method));
        }

        return $callable(...$arguments);
    }

    private function make(PayPoConfig $config): PayPoClient
    {
        return new PayPoClient(
            $this->http,
            new AccessTokenProvider($this->http, $this->cache, $config),
            $config,
            $this->notifyUrlResolver,
        );
    }

    private function configFor(string $name): PayPoConfig
    {
        $connections = $this->config->get('paypo.connections');
        $connection = is_array($connections) ? ($connections[$name] ?? null) : null;

        if (is_array($connection)) {
            /** @var array<string, mixed> $connection */
            return PayPoConfig::fromArray(self::merge($this->shared(), $connection), $name);
        }

        if ((! is_array($connections) || $connections === []) && $name === $this->getDefaultConnection()) {
            // Single-account configuration with credentials at the top level.
            return PayPoConfig::fromArray($this->shared(), $name);
        }

        $resolved = $this->resolver !== null ? ($this->resolver)($name) : null;

        if ($resolved instanceof PayPoConfig) {
            return $resolved;
        }

        if (is_array($resolved)) {
            return PayPoConfig::fromArray(self::merge($this->shared(), $resolved + ['name' => $name]), $name);
        }

        throw InvalidConfiguration::unknownConnection($name);
    }

    /**
     * Applies connection options over the shared ones; empty values (unset environment variables) do not override.
     *
     * @param array<string, mixed> $shared
     * @param array<array-key, mixed> $connection
     * @return array<string, mixed>
     */
    private static function merge(array $shared, array $connection): array
    {
        foreach ($connection as $key => $value) {
            if ($value !== null && $value !== '') {
                $shared[(string) $key] = $value;
            }
        }

        return $shared;
    }

    /**
     * Options shared by every connection: everything outside "default", "connections" and "notifications".
     *
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        $config = $this->config->get('paypo', []);
        $config = is_array($config) ? $config : [];
        unset($config['default'], $config['connections'], $config['notifications']);

        /** @var array<string, mixed> $config */
        return $config;
    }
}

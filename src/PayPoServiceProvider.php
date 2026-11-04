<?php

declare(strict_types=1);

namespace Beziworld\PayPo;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Registers the PayPo connection manager, the client of the default connection and the notification route.
 */
final class PayPoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/paypo.php', 'paypo');

        $this->app->singleton(PayPoManager::class, static function (Application $app): PayPoManager {
            $config = $app->make(Config::class);

            return new PayPoManager(
                $app->make(HttpFactory::class),
                $config,
                $app->make(CacheFactory::class)->store(),
                static function (PayPoConfig $paypo) use ($app, $config): ?string {
                    $route = (string) $config->get('paypo.notifications.name', 'paypo.notification');

                    if ($paypo->name === null || ! (bool) $config->get('paypo.notifications.enabled', true)) {
                        return null;
                    }

                    try {
                        return $app->make(UrlGenerator::class)->route($route, ['connection' => $paypo->name]);
                    } catch (RouteNotFoundException) {
                        return null;
                    }
                },
            );
        });

        // The client and configuration of the default connection.
        $this->app->bind(PayPoClient::class, static fn(Application $app): PayPoClient => $app->make(PayPoManager::class)->connection());
        $this->app->bind(PayPoConfig::class, static fn(Application $app): PayPoConfig => $app->make(PayPoClient::class)->config());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/paypo.php' => $this->app->configPath('paypo.php')], 'paypo-config');
        }

        if ((bool) $this->app->make(Config::class)->get('paypo.notifications.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/paypo.php');
        }
    }
}

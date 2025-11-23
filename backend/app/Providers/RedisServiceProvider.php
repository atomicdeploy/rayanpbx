<?php

namespace App\Providers;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

/**
 * Custom Redis Service Provider with automatic fallback from phpredis to predis.
 *
 * This provider overrides Laravel's default RedisServiceProvider to add automatic
 * fallback logic when the PHP Redis extension is not available.
 */
class RedisServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('redis', function ($app) {
            $config = $app->make('config')->get('database.redis', []);

            // Get the desired client type (without modifying the config)
            $client = Arr::get($config, 'client', 'phpredis');

            // Try to use phpredis first if it's available
            if ($client === 'phpredis' && ! extension_loaded('redis')) {
                // Log the fallback
                if ($app->make('config')->get('app.debug')) {
                    logger()->warning('PHP Redis extension not available, falling back to predis');
                }

                // Fallback to predis
                $client = 'predis';

                // Update the client in config for this request
                $config['client'] = $client;
            }

            return new RedisManager($app, $client, $config);
        });

        $this->app->bind('redis.connection', function ($app) {
            return $app['redis']->connection();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

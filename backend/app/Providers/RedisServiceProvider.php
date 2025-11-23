<?php

namespace App\Providers;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

class RedisServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('redis', function ($app) {
            $config = $app->make('config')->get('database.redis', []);

            // Get the desired client type
            $client = Arr::pull($config, 'client', 'phpredis');

            // Try to use phpredis first if it's available
            if ($client === 'phpredis' && ! extension_loaded('redis')) {
                // Log the fallback
                if ($app->make('config')->get('app.debug')) {
                    logger()->warning('PHP Redis extension not available, falling back to predis');
                }

                // Fallback to predis
                $client = 'predis';
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

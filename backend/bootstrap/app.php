<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        App\Providers\EnvLoaderServiceProvider::class,
        App\Providers\RedisServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        App\Providers\RateLimitServiceProvider::class,
    ])
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Register VoIP phone whitelist middleware alias
        $middleware->alias([
            'voip.whitelist' => \App\Http\Middleware\VoipPhoneWhitelist::class,
        ]);

        $middleware->redirectGuestsTo(fn () => throw new \Illuminate\Auth\AuthenticationException());
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            return $request->is('api/*');
        });
    })->create();

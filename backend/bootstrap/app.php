<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        App\Providers\EnvLoaderServiceProvider::class,
        App\Providers\RedisServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        App\Providers\RateLimitServiceProvider::class,
        App\Providers\ExtensionSyncServiceProvider::class,
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
        // Ensure JSON is rendered for API routes
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            // Always return JSON for API routes or when JSON is explicitly requested
            return $request->is('api/*') || 
                   $request->expectsJson() ||
                   $request->wantsJson() ||
                   $request->header('Accept') === 'application/json';
        });
        
        // Handle 404 errors for API routes
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Resource not found',
                    'message' => $e->getMessage() ?: 'The requested resource was not found.',
                    'http_code' => 404,
                ], 404);
            }
        });
        
        // Handle method not allowed errors for API routes
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Method not allowed',
                    'message' => $e->getMessage() ?: 'The HTTP method is not allowed for this endpoint.',
                    'http_code' => 405,
                ], 405);
            }
        });
        
        // Handle general HTTP exceptions for API routes
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson() || $request->wantsJson()) {
                $statusCode = $e->getStatusCode();
                return response()->json([
                    'success' => false,
                    'error' => 'HTTP Error',
                    'message' => $e->getMessage() ?: 'An HTTP error occurred.',
                    'http_code' => $statusCode,
                ], $statusCode);
            }
        });
        
        // Handle validation exceptions for API routes
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                    'http_code' => 422,
                ], 422);
            }
        });
        
        // Handle authentication exceptions for API routes
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthenticated',
                    'message' => 'Authentication is required to access this resource.',
                    'http_code' => 401,
                ], 401);
            }
        });
    })->create();

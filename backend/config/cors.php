<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'health'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_merge(
        [
            env('FRONTEND_URL', 'http://localhost:3000'),
            env('APP_URL', 'http://localhost:3000'),
            'http://localhost:3000',
            'http://127.0.0.1:3000',
        ],
        // Support for comma-separated CORS_ALLOWED_ORIGINS env variable
        env('CORS_ALLOWED_ORIGINS') ? explode(',', env('CORS_ALLOWED_ORIGINS')) : []
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

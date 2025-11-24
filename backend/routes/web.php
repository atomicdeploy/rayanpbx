<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'RayanPBX API',
        'status' => 'running',
        'app_name' => env('APP_NAME'),
        'app_env' => env('APP_ENV'),
        'asterisk_ami_host' => env('ASTERISK_AMI_HOST'),
        'env_loader_test' => 'EnvLoaderServiceProvider loaded successfully'
    ]);
});

Route::get('/test-env-loader', function () {
    return response()->json([
        'message' => 'Testing EnvLoaderServiceProvider',
        'dotenv_fix' => 'Using load() instead of overload()',
        'environment_variables_loaded' => [
            'APP_NAME' => env('APP_NAME'),
            'APP_ENV' => env('APP_ENV'),
            'DB_CONNECTION' => env('DB_CONNECTION'),
            'ASTERISK_AMI_HOST' => env('ASTERISK_AMI_HOST'),
        ],
        'status' => 'success'
    ]);
});

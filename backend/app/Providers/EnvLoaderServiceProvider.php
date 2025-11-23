<?php

namespace App\Providers;

use Dotenv\Dotenv;
use Illuminate\Support\ServiceProvider;

class EnvLoaderServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     * 
     * Loads .env files from multiple paths in priority order.
     * Later paths override earlier ones:
     * 1. /opt/rayanpbx/.env
     * 2. /usr/local/rayanpbx/.env
     * 3. /etc/rayanpbx/.env
     * 4. <root of the project>/.env (found by looking for VERSION file)
     * 5. <backend directory>/.env (Laravel default)
     */
    public function boot(): void
    {
        $this->loadEnvironmentFiles();
    }

    /**
     * Load environment files from multiple paths
     */
    protected function loadEnvironmentFiles(): void
    {
        $paths = [
            '/opt/rayanpbx',
            '/usr/local/rayanpbx',
            '/etc/rayanpbx',
        ];

        // Add project root (parent of backend)
        $projectRoot = dirname(base_path());
        if (file_exists($projectRoot . '/VERSION')) {
            $paths[] = $projectRoot;
        }

        // Add Laravel backend path (default, already loaded by framework)
        // We don't need to add it here as Laravel loads it automatically

        // Track which paths we've loaded
        $loadedPaths = [];

        // Load each .env file in order
        foreach ($paths as $path) {
            $envFile = $path . '/.env';
            
            // Skip if file doesn't exist or already loaded
            if (!file_exists($envFile) || in_array($envFile, $loadedPaths)) {
                continue;
            }

            try {
                // Use createMutable to allow overriding existing values
                // Always use overload() since Laravel has already loaded its default .env
                // before this service provider runs
                $dotenv = Dotenv::createMutable($path);
                $dotenv->overload();
                
                $loadedPaths[] = $envFile;
            } catch (\Exception $e) {
                // Log warning but don't fail
                if (config('app.debug')) {
                    error_log("Warning: Failed to load .env file from {$envFile}: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }
}

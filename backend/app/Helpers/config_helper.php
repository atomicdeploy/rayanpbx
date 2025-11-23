<?php

/**
 * Shared Configuration Helper
 * Loads configuration from root .env file for all components
 */

if (!function_exists('load_rayanpbx_config')) {
    /**
     * Load RayanPBX configuration from root .env
     */
    function load_rayanpbx_config()
    {
        $rootPath = dirname(__DIR__, 2);
        $envFile = $rootPath . '/.env';
        
        if (file_exists($envFile)) {
            $dotenv = Dotenv\Dotenv::createImmutable($rootPath);
            $dotenv->load();
        }
    }
}

// Auto-load config when this file is included
load_rayanpbx_config();

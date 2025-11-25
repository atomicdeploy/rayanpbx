<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Asterisk Configuration
    |--------------------------------------------------------------------------
    */
    'asterisk' => [
        'ami_host' => env('ASTERISK_AMI_HOST', '127.0.0.1'),
        'ami_port' => env('ASTERISK_AMI_PORT', 5038),
        'ami_username' => env('ASTERISK_AMI_USERNAME', 'admin'),
        'ami_secret' => env('ASTERISK_AMI_SECRET', ''),
        'config_path' => env('ASTERISK_CONFIG_PATH', '/etc/asterisk'),
        'pjsip_config' => env('ASTERISK_PJSIP_CONFIG', '/etc/asterisk/pjsip.conf'),
        'extensions_config' => env('ASTERISK_EXTENSIONS_CONFIG', '/etc/asterisk/extensions.conf'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extension Configuration
    |--------------------------------------------------------------------------
    */
    'extension' => [
        'range_start' => env('RAYANPBX_EXTENSION_RANGE_START', 100),
        'range_end' => env('RAYANPBX_EXTENSION_RANGE_END', 999),
        'default_context' => 'from-internal',
        'default_transport' => 'udp',
        'default_codecs' => explode(',', env('SIP_CODECS', 'ulaw,alaw,g722,opus')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trunk Configuration
    |--------------------------------------------------------------------------
    */
    'trunk' => [
        'default_prefix' => env('RAYANPBX_DEFAULT_TRUNK_PREFIX', '9'),
        'default_context' => 'from-trunk',
        'default_transport' => 'udp',
    ],

    /*
    |--------------------------------------------------------------------------
    | SIP Configuration
    |--------------------------------------------------------------------------
    */
    'sip' => [
        'realm' => env('SIP_REALM', 'rayanpbx.local'),
        'transport' => env('SIP_TRANSPORT', 'udp'),
        'port' => env('SIP_PORT', 5060),
    ],

    /*
    |--------------------------------------------------------------------------
    | SIP Server IP
    |--------------------------------------------------------------------------
    | Used for phone provisioning and SIP registration
    */
    'sip_server_ip' => env('SIP_SERVER_IP', '127.0.0.1'),

    /*
    |--------------------------------------------------------------------------
    | Phone Provisioning Configuration
    |--------------------------------------------------------------------------
    */
    'provisioning_base_url' => env('PROVISIONING_BASE_URL', null),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    | Base URL for GrandStream phone Action URL webhooks
    | Set to your public URL if different from APP_URL.
    | Example: env('WEBHOOK_BASE_URL', null)
    | e.g., 'https://pbx.example.com/api/grandstream/webhook'
    */
    'webhook_base_url' => env('WEBHOOK_BASE_URL', null),

    /*
    |--------------------------------------------------------------------------
    | VoIP Webhook Security
    |--------------------------------------------------------------------------
    | IP whitelisting for VoIP phone webhook endpoints.
    | By default, private network IPs and registered phone IPs are allowed.
    */
    
    // Allow private network IPs (10.x.x.x, 172.16-31.x.x, 192.168.x.x)
    'voip_webhook_allow_private' => env('VOIP_WEBHOOK_ALLOW_PRIVATE', true),
    
    // Additional whitelisted IP addresses (array)
    'voip_webhook_whitelist' => array_filter(explode(',', env('VOIP_WEBHOOK_WHITELIST', ''))),
    
    // Additional CIDR ranges to whitelist (array)
    // Example: '10.0.0.0/8,192.168.1.0/24'
    'voip_webhook_cidr_whitelist' => array_filter(explode(',', env('VOIP_WEBHOOK_CIDR_WHITELIST', ''))),
    
    // Cache TTL for registered phone IPs (in seconds)
    'voip_webhook_cache_ttl' => env('VOIP_WEBHOOK_CACHE_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    */
    'security' => [
        'pam_enabled' => env('RAYANPBX_PAM_ENABLED', true),
        'rate_limit_login' => env('RATE_LIMIT_LOGIN', 5),
        'rate_limit_login_decay' => env('RATE_LIMIT_LOGIN_DECAY', 60),
    ],
];

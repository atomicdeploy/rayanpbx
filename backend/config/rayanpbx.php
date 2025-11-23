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
    | Security Configuration
    |--------------------------------------------------------------------------
    */
    'security' => [
        'pam_enabled' => env('RAYANPBX_PAM_ENABLED', true),
        'rate_limit_login' => env('RATE_LIMIT_LOGIN', 5),
        'rate_limit_login_decay' => env('RATE_LIMIT_LOGIN_DECAY', 60),
    ],
];

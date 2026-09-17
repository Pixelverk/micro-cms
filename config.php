<?php
declare(strict_types=1);

return [

    // --------------------------------------------------
    // Environment
    // --------------------------------------------------
    'env' => 'local', // local | production

    // Base URL in root
    'url' => '',
    // Base URL in subfolder
    //'url' => '/micro-cms',

    'perf_logging' => false,

    'setup_completed' => true,
    
    // --------------------------------------------------
    // Session & auth
    // --------------------------------------------------
    'session' => [
        'timeout' => 3600, // seconds
    ],

    'security' => [
        'password_min_length' => 10,

        // Signs the cache-safe tokens used by public forms (contact, newsletter).
        // Leave null to derive a stable secret from the install path, or set a
        // random string here (recommended for production).
        'form_secret' => null,

        // Login throttling
        'login_max_attempts' => 5,
        'login_lockout_seconds' => 900,
    ],

    'defaults' => [
        'layout' => 'default',
        'status' => 'published',
    ],

    'cache_lifetime' => 3600, // seconds

    // How long to keep audit-log entries (see core/helpers/activity.php).
    'activity' => [
        'retention_days' => 180,
    ],

];

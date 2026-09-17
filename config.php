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

    // Debug switch: when true, request timings are appended to
    // storage/logs/perf.log. Leave it off in production.
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

    'cache_lifetime' => 3600, // seconds

    // How long to keep audit-log entries (see core/helpers/activity.php).
    'activity' => [
        'retention_days' => 180,
    ],

    // How many revisions to keep per content item (see core/helpers/versions.php).
    'versions' => [
        'keep' => 20,
    ],

    // How long trashed content is kept before it is purged automatically
    // (see core/helpers/content.php).
    'trash' => [
        'retention_days' => 30,
    ],

];

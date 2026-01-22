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

    'perf_logging' => false,  // saves to /storage/logs if true
    
    // --------------------------------------------------
    // Session & auth
    // --------------------------------------------------
    'session' => [
        'timeout' => 3600, // seconds
    ],

    'security' => [
        'password_min_length' => 10,
    ],

    'defaults' => [
        'layout' => 'default',
        'status' => 'published',
    ],

    'cache_lifetime' => 3600, // seconds

];

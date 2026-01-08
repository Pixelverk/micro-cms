<?php
// editor/_core/config.php

declare(strict_types=1);

return [

    // --------------------------------------------------
    // Environment
    // --------------------------------------------------
    'env' => 'local', // local | production

    // --------------------------------------------------
    // Session & auth
    // --------------------------------------------------
    'session' => [
        'timeout' => 3600, // seconds (1 hour)
    ],

    'security' => [
        'password_min_length' => 10,
    ],

    // --------------------------------------------------
    // Paths
    // --------------------------------------------------
    'paths' => [
        'project_root' => dirname(__DIR__, 2),
        'editor_root'  => dirname(__DIR__),
        'pages'        => dirname(__DIR__, 2) . '/pages',
        'assets'       => dirname(__DIR__, 2) . '/_assets',
        'components'   => dirname(__DIR__, 2) . '/_components',
    ],

];

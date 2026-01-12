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
        'project_root' => __DIR__,
        'editor_root'  => __DIR__ . '/editor',
        'pages'        => __DIR__ . '/_data/pages',
        'assets'       => __DIR__ . '/_assets',
        'components'   => __DIR__ . '/_components',
    ],

];

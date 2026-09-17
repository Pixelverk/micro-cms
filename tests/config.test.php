<?php
declare(strict_types=1);

/*
 * Test configuration. Mirrors config.php with test-specific values.
 * Never edit the tracked config.php from tests.
 */

$base = require dirname(__DIR__) . '/config.php';

return array_replace_recursive($base, [
    'env'              => 'local',
    'url'              => '',
    'perf_logging'     => false,
    'setup_completed'  => true, // the installer rewrites this; tests seed explicitly
    // Long enough that cache writes can be observed and re-served; suites clear
    // the cache directory whenever they need a cold render.
    'cache_lifetime'   => 3600,

    // Keep every artefact of a test run inside tests/.tmp/
    'storage_path'     => __DIR__ . '/.tmp/storage',

    'security'         => [
        'form_secret'           => str_repeat('t', 32),
        'login_max_attempts'    => 3,
        'login_lockout_seconds' => 60,
    ],
]);

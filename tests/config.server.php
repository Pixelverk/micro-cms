<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test-only config for the built-in server
|--------------------------------------------------------------------------
| Same as tests/config.test.php but with `setup_completed = true` forced,
| because the seeded test database already exists.
|
*/

$config = require __DIR__ . '/config.test.php';

$config['setup_completed'] = true;
$config['storage_path']    = __DIR__ . '/.tmp/storage';

return $config;

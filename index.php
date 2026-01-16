<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Determine Mode Based on Request Path
|--------------------------------------------------------------------------
*/
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = rtrim($path, '/');

if (str_starts_with($path, '/admin')) {
    $mode = 'admin';
} else {
    $mode = 'frontend';
}

/*
|--------------------------------------------------------------------------
| Load Le Bootstraps
|--------------------------------------------------------------------------
*/
require __DIR__ . '/core/bootstrap/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Bootstrap & Handle Request
|--------------------------------------------------------------------------
*/
bootstrap($mode);

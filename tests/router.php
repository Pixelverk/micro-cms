<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Router for PHP's built-in server
|--------------------------------------------------------------------------
|
|   php -S 127.0.0.1:8080 tests/router.php
|
| Mirrors the rules in .htaccess: assets are served directly, core/ and
| storage/ are blocked, everything else goes through index.php.
|
*/

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$root = dirname(__DIR__);
$file = $root . $path;

// Serve real files for public asset paths only.
if (is_file($file)) {
    $public = str_starts_with($path, '/theme/assets/')
        || str_starts_with($path, '/admin/assets/')
        || $path === '/favicon.ico';

    if ($public) {
        return false; // let the built-in server handle it
    }

    http_response_code(403);
    exit('Forbidden');
}

// Block sensitive directories.
if (str_starts_with($path, '/core/') || str_starts_with($path, '/storage/')) {
    http_response_code(403);
    exit('Forbidden');
}

require $root . '/index.php';

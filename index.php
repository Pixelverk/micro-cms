<?php
declare(strict_types=1);

$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = rtrim($request, '/');

$config = require 'config.php';

if (!empty($config['perf_logging'])){
    require __DIR__ . '/core/helpers/perf.php';
    $logging = true;
}

define('CMS_PATH', __DIR__);
define('CORE_PATH', CMS_PATH . '/core');
define('STORAGE_PATH', CMS_PATH . '/storage');
define('CONTENT_PATH', STORAGE_PATH . '/content');

// Ensure Storage Directories Exist
foreach (['', '/cache', '/media', '/logs'] as $dir) {
    $full = STORAGE_PATH . $dir;
    if (!is_dir($full)) mkdir($full, 0775, true);
}

// First Run SQLite Setup
if (!file_exists(STORAGE_PATH . '/data.sqlite')) {
    require CORE_PATH . '/db_setup.php';
}

// 1. Media
if (str_starts_with($path, '/media')) {
    require __DIR__ . '/core/bootstrap/media.php';
    serveMedia($request);
    if ($logging) { stop_logging(); };
    exit;
}

// 2. Admin
if (str_starts_with($path, '/admin')) {
    require __DIR__ . '/core/bootstrap/admin.php';
    serveAdmin($request);
    if ($logging) { stop_logging(); };
    exit;
}

// 3. Frontend
require __DIR__ . '/core/bootstrap/front.php';

// 3.1 Cached HTML
if ($file = checkCache($request, $config)) {
    serveCached($file, $config);
    if ($logging) { stop_logging(true); };
    exit;
}

// 3.2 Frontend dynamic render
serveFresh($request);
if ($logging) { stop_logging(); };
exit;
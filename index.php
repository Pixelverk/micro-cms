<?php
declare(strict_types=1);

// figure out where we are
define('CMS_PATH', __DIR__);
define('CORE_PATH', CMS_PATH . '/core');
define('STORAGE_PATH', CMS_PATH . '/storage');

// decide timezone
date_default_timezone_set('UTC');

// get some info
$config = require 'config.php';
$logging = ($config['perf_logging'] ?? false) === true;
$doSetup = ($config['setup_completed'] ?? false) !== true;

// check for performance logging
if ($logging) {
    require CORE_PATH . '/helpers/perf.php';
}

// check for first run setup
if ($doSetup) {
    require CORE_PATH . '/helpers/setup.php';
}

// 0. Begin request processing 
$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = rtrim($request, '/');

// 1. Media
if (str_starts_with($path, '/media')) {
    require CORE_PATH . '/bootstrap/media.php';
    serveMedia($request);
    if ($logging) { stop_logging(); };
    exit;
}

// 2. Admin
if (str_starts_with($path, '/admin')) {
    require CORE_PATH . '/bootstrap/admin.php';
    serveAdmin($request);
    if ($logging) { stop_logging(); };
    exit;
}

// 3. Frontend
require CORE_PATH . '/bootstrap/front.php';

// 3.1 Cached HTML
if ($file = checkCache($request, $config)) {
    serveCached($file, $config);
    if ($logging) { stop_logging(true); };
    exit;
}

// 3.2 Database render
serveFresh($request);
if ($logging) { stop_logging(); };
exit;
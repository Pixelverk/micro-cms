<?php
declare(strict_types=1);

// figure out where we are
define('CMS_PATH', __DIR__);
define('CORE_PATH', CMS_PATH . '/core');

// decide timezone
date_default_timezone_set('UTC');

// get some info
// CMS_CONFIG_FILE lets tooling (tests, alternate environments) point at its own
// config; everything else keeps using ./config.php. A config may also relocate
// storage, which keeps test runs out of the real storage/ directory.
$configFile = getenv('CMS_CONFIG_FILE') ?: 'config.php';
$config = require $configFile;

define('STORAGE_PATH', $config['storage_path'] ?? CMS_PATH . '/storage');

$logging = ($config['perf_logging'] ?? false) === true;
$dbPath = STORAGE_PATH . '/data.sqlite';

function database_is_ready(string $path): bool
{
    if (!is_file($path) || filesize($path) === 0) {
        return false;
    }

    try {
        $pdo = new PDO('sqlite:' . $path);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (count(array_intersect(['content', 'settings', 'users', 'menus'], $tables)) !== 4) {
            return false;
        }

        $requiredColumns = [
            'content' => ['id', 'type', 'slug', 'published_at', 'body'],
            'settings' => ['key', 'value'],
            'menus' => ['label', 'slug', 'items'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            $actual = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (count(array_diff($columns, $actual)) > 0) {
                return false;
            }
        }

        return true;
    } catch (Throwable $exception) {
        return false;
    }
}

$databaseNeedsSetup = !database_is_ready($dbPath);
$doSetup = ($config['setup_completed'] ?? false) !== true || $databaseNeedsSetup;

// A zero-byte or partial database cannot be repaired by the installer because
// setup.php intentionally refuses to overwrite an existing database file.
if ($databaseNeedsSetup && is_file($dbPath)) {
    unlink($dbPath);
}

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

// The session must exist before the cache is consulted: a cached page is only
// ever served to an anonymous visitor, and preview requests must be rendered
// live. Both decisions depend on the session.
require_once CORE_PATH . '/helpers/common.php';
bootstrap_core();
session_boot();

// Schema migrations must run before ANY query reads content: a database created
// by an older release lacks columns the current read paths select, and the
// cache check below already touches content (via the homepage setting). A
// database that cannot be upgraded fails loudly instead of 500-ing.
migrate_before_read();

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
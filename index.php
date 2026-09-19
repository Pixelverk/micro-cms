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

// 0. Begin request processing
$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = rtrim($request, '/');
$cacheLifetime = (int) ($config['cache_lifetime'] ?? 3600);

// check for performance logging (before the firebreak, so a hit is timed)
if ($logging) {
    require CORE_PATH . '/helpers/perf.php';
}

/*
|--------------------------------------------------------------------------
| 1. Front-end cache firebreak
|--------------------------------------------------------------------------
| A cached page is a file on disk, so an anonymous GET is served here before
| the installer check, the helper set, the session and the migrations.
|
| It only fires without a session cookie. Preview requires a signed-in user,
| and a signed-in user always has a session, so a cookie-less request can be
| neither. Anything with a session falls through to checkCache() below, which
| decides with the real session state after the full boot.
|--------------------------------------------------------------------------
*/
// Scheduled publishing is checked after a public request at most once a
// minute. Let that one request take the full path instead of skipping the check
// on a cache hit.
$publishMarker   = STORAGE_PATH . '/.publish-check';
$publishCheckDue = !is_file($publishMarker) || (time() - (int) filemtime($publishMarker)) >= 60;

if (!str_starts_with($path, '/admin')
    && !str_starts_with($path, '/media')
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && ($config['setup_completed'] ?? false) === true
    && empty($_COOKIE[session_name()])
    && !isset($_GET['preview'])
    && !str_starts_with(ltrim($path, '/'), 'search')
    // A paged listing carries its page in the query string, which the cache key
    // does not include, so it must render live. Checked inline rather than with
    // pagination_is_paged_request() to keep this branch free of the helper set.
    && (int) ($_GET['page'] ?? 1) <= 1
    && !$publishCheckDue
) {
    require_once CORE_PATH . '/helpers/cache.php';
    $cacheFile = cache_file_for($request);

    // The database only has to exist: a cache hit reads nothing from it, but a
    // missing or empty one means the installer still has to run.
    if (is_file($cacheFile)
        && (time() - filemtime($cacheFile) < $cacheLifetime)
        && is_file($dbPath) && filesize($dbPath) > 0
    ) {
        // Count the hit with the smallest boot that can do it: the view is
        // appended to a buffer file here and batched into the database later.
        require_once CORE_PATH . '/helpers/common.php';
        require_once CORE_PATH . '/helpers/analytics.php';
        analytics_record_view(null, true);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=' . $cacheLifetime);
        header('X-Cache: HIT');
        readfile($cacheFile);

        if ($logging) { stop_logging(true); }
        exit;
    }
}

// First-run installer and the database sanity check.
$databaseNeedsSetup = !database_is_ready($dbPath);
$doSetup = ($config['setup_completed'] ?? false) !== true || $databaseNeedsSetup;

// A zero-byte or partial database cannot be repaired by the installer because
// setup.php intentionally refuses to overwrite an existing database file.
if ($databaseNeedsSetup && is_file($dbPath)) {
    unlink($dbPath);
}

// check for first run setup
if ($doSetup) {
    require CORE_PATH . '/helpers/setup.php';
}

// 2. Media
if (str_starts_with($path, '/media')) {
    require CORE_PATH . '/bootstrap/media.php';
    serveMedia($request);
    if ($logging) { stop_logging(); };
    exit;
}

// 3. Admin
if (str_starts_with($path, '/admin')) {
    require CORE_PATH . '/bootstrap/admin.php';
    serveAdmin($request);
    if ($logging) { stop_logging(); };
    exit;
}

// 4. Frontend
require CORE_PATH . '/bootstrap/front.php';

// The front path's single boot point; serveCached()/serveFresh() assume it has
// run. Only a request that already carries a session cookie can be signed in
// or previewing, so only that request needs a session. Anonymous visitors stay
// sessionless: no cookie, no session file, and the firebreak above stays fast
// on every visit.
require_once CORE_PATH . '/helpers/common.php';
bootstrap_core();

if (isset($_COOKIE[session_name()])) {
    session_boot();
    session_validate_identity();
}

// Schema migrations must run before ANY query reads content: a database created
// by an older release lacks columns the current read paths select, and the
// cache check below already touches content (via the homepage setting). A
// database that cannot be upgraded fails loudly instead of 500-ing.
migrate_before_read();

// Move any views buffered since the last full-path request into the database.
analytics_maybe_ingest();

// 4.1 Cached HTML
if ($file = checkCache($request, $config)) {
    serveCached($file, $config);
    if ($logging) { stop_logging(true); };
    exit;
}

// 4.2 Database render
serveFresh($request);
if ($logging) { stop_logging(); };
exit;
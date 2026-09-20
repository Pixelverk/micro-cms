<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
|
| Boots the CMS against a throwaway storage directory so tests never touch
| the developer's storage/ folder. No composer, no PHPUnit — just PHP.
|
| Usage: php tests/run.php
|
*/

define('CMS_PATH', dirname(__DIR__));
define('CORE_PATH', CMS_PATH . '/core');

// The gitignored scratch space for tests (config.test.php points here too).
$testRoot    = CMS_PATH . '/tests/.tmp';
$testStorage = getenv('CMS_TEST_STORAGE') ?: $testRoot . '/storage';

// Refuse to run against the real storage directory.
$realStorage = realpath(CMS_PATH . '/storage');
$resolvedTest = is_dir($testStorage) ? realpath($testStorage) : $testStorage;

if ($realStorage !== false && $resolvedTest !== false && str_starts_with((string) $resolvedTest, (string) $realStorage)) {
    fwrite(STDERR, "Refusing to run: test storage resolves inside the real storage directory.\n");
    exit(1);
}

define('STORAGE_PATH', $testStorage);
define('CMS_SETUP_READONLY', true);

foreach (['', '/cache', '/media', '/logs', '/sessions', '/imports'] as $dir) {
    if (!is_dir(STORAGE_PATH . $dir)) {
        mkdir(STORAGE_PATH . $dir, 0775, true);
    }
}

// config() reads CMS_CONFIG_FILE, which repoints storage at the test directory.
putenv('CMS_CONFIG_FILE=' . CMS_PATH . '/tests/config.test.php');

// Sessions must not touch the system session directory (often read-only).
define('CMS_SESSION_PATH', STORAGE_PATH . '/sessions');

$_SERVER['REQUEST_METHOD'] ??= 'GET';
$_SERVER['REQUEST_URI'] ??= '/';
$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] ??= 'micro-cms-tests';

require_once CORE_PATH . '/helpers/common.php';
require CORE_PATH . '/helpers/csrf.php';
require CORE_PATH . '/helpers/validate.php';
require CORE_PATH . '/helpers/cache.php';
require CORE_PATH . '/helpers/pagination.php';
require CORE_PATH . '/helpers/settings.php';
require CORE_PATH . '/helpers/content.php';
require CORE_PATH . '/helpers/forms.php';
require CORE_PATH . '/helpers/menus.php';
require CORE_PATH . '/helpers/sitemap.php';
require CORE_PATH . '/helpers/robots.php';
require CORE_PATH . '/helpers/publishing.php';
require CORE_PATH . '/helpers/versions.php';
require CORE_PATH . '/helpers/search.php';
require CORE_PATH . '/helpers/export.php';
require CORE_PATH . '/helpers/migrate.php';
require CORE_PATH . '/helpers/activity.php';
require CORE_PATH . '/helpers/analytics.php';
require CORE_PATH . '/helpers/health.php';
require CORE_PATH . '/helpers/redirects.php';
require CORE_PATH . '/helpers/zip.php';
require CORE_PATH . '/helpers/seo.php';
require CORE_PATH . '/helpers/icons.php';
require CORE_PATH . '/helpers/admin.php';
require CORE_PATH . '/helpers/throttle.php';
require_once CORE_PATH . '/auth.php';
require CORE_PATH . '/db.php';
require CORE_PATH . '/render.php';
require CORE_PATH . '/router.php';


// Must run before any other session_* call, including php.ini defaults.
session_save_path(CMS_SESSION_PATH);
session_boot();

require __DIR__ . '/helpers.php';

/**
 * Restore the seeded demo database.
 *
 * The installer runs once per schema revision, into a template file; every
 * later call is a file copy. That keeps each suite isolated (it still starts
 * from a pristine database) without a PHP subprocess per suite.
 */
function test_fresh_database(): void
{
    $dbPath       = STORAGE_PATH . '/data.sqlite';
    $templatePath = STORAGE_PATH . '/seed-template.sqlite';

    // Rebuild when the template is missing or predates what it is built from:
    // the schema, or the demo files the installer seeds.
    $sourceTime = max(
        filemtime(CORE_PATH . '/helpers/setup.php'),
        filemtime(CMS_PATH . '/theme/demo/content.json'),
        filemtime(CMS_PATH . '/theme/demo/settings.json')
    );

    if (!is_file($templatePath) || filemtime($templatePath) < $sourceTime) {
        test_build_seed_template($templatePath);
        return;
    }

    if (is_file($dbPath)) {
        unlink($dbPath);
    }

    if (!copy($templatePath, $dbPath)) {
        throw new RuntimeException('Could not restore the seeded test database.');
    }
}

/**
 * Run the real installer once and store the result as the seed template.
 * Runs in a subprocess because setup.php both defines functions and exits.
 */
function test_build_seed_template(string $templatePath): void
{
    $dbPath = STORAGE_PATH . '/data.sqlite';

    if (is_file($dbPath)) {
        unlink($dbPath);
    }

    $code = <<<'PHP'
require %s . '/tests/bootstrap.php';
require CORE_PATH . '/helpers/setup.php';
PHP;

    $command = escapeshellarg(PHP_BINARY)
        . ' -d error_reporting=E_ALL'
        . ' -r ' . escapeshellarg(sprintf($code, var_export(CMS_PATH, true)))
        . ' 2>&1';

    exec($command, $output, $exitCode);

    if (!is_file($dbPath)) {
        throw new RuntimeException(
            "Could not seed the test database.\n" . implode("\n", $output)
        );
    }

    if (!copy($dbPath, $templatePath)) {
        throw new RuntimeException('Could not store the seed template.');
    }
}

/**
 * Simulate a request without touching the real SAPI.
 */
function test_request(string $method, string $uri, array $post = [], array $get = [], array $session = []): void
{
    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    $_SERVER['REQUEST_URI'] = $uri;
    $_POST = $post;
    $_GET = $get;
    $_SESSION = $session;
}

/**
 * Absolute path of the scratch directory that holds storage/ and helpers.
 */
function test_tmp_root(): string
{
    return dirname(STORAGE_PATH);
}

/**
 * Run a PHP snippet in a fresh process against the test storage.
 * Used by the HTTP suite, which needs its own server process.
 */
function test_php(array $lines): array
{
    $code = "require " . var_export(CMS_PATH . '/tests/bootstrap.php', true) . ";\n" . implode("\n", $lines);

    // A hard timeout keeps a hung child (e.g. a database lock) from taking the
    // whole suite down; 124 is the timeout exit code.
    $command = 'timeout 25 ' . escapeshellarg(PHP_BINARY)
        . ' -d error_reporting=E_ALL'
        . ' -r ' . escapeshellarg($code)
        . ' 2>&1';

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    return [$output, $exitCode];
}

function test_login_session(int $userId = 1, string $username = 'admin'): void
{
    $_SESSION = [
        'user_id'    => $userId,
        'username'   => $username,
        'login_time' => time(),
    ];

    // The preview token lives in its own cookie, not the session.
    $_COOKIE[preview_cookie_name()] = 'testpreviewtoken0123456789abcdef';
}

/**
 * Simulate a signed-in editor requesting a page with ?preview=<token>.
 * This is what unlocks drafts and disables the HTML cache.
 */
function test_preview_request(string $uri = '/'): void
{
    test_login_session();

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $uri . (str_contains($uri, '?') ? '&' : '?') . 'preview=' . $_COOKIE[preview_cookie_name()];
    $_GET = ['preview' => $_COOKIE[preview_cookie_name()]];
    $_POST = [];
}

/**
 * Back to an anonymous visit.
 */
function test_anonymous_request(string $uri = '/'): void
{
    $_SESSION = [];
    $_GET = [];
    $_POST = [];
    unset($_COOKIE[preview_cookie_name()]);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $uri;
}

/**
 * Simple cache-directory helpers.
 */
function test_cache_files(): array
{
    return glob(STORAGE_PATH . '/cache/*.html') ?: [];
}

function test_clear_cache_files(): void
{
    foreach (test_cache_files() as $file) {
        @unlink($file);
    }
}

<?php
declare(strict_types=1);

function bootstrap(string $mode): void
{
    /*
    |--------------------------------------------------------------------------
    | Core Paths
    |--------------------------------------------------------------------------
    */
    define('CMS_PATH', dirname(__DIR__, 2));
    define('CORE_PATH', CMS_PATH . '/core');
    define('STORAGE_PATH', CMS_PATH . '/storage');
    define('CONTENT_PATH', STORAGE_PATH . '/content');
    define('MENUS_FILE', STORAGE_PATH . '/menus.json');
    define('USER_FILE', STORAGE_PATH . '/users.json');
    define('SETTINGS_FILE', STORAGE_PATH . '/settings.json');

    /*
    |--------------------------------------------------------------------------
    | Load Config
    |--------------------------------------------------------------------------
    */
    $config = require CMS_PATH . '/config.php';

    /*
    |--------------------------------------------------------------------------
    | Ensure Storage Directories Exist
    |--------------------------------------------------------------------------
    */
    foreach (['', '/cache', '/uploads'] as $dir) {
        $full = STORAGE_PATH . $dir;
        if (!is_dir($full)) mkdir($full, 0775, true);
    }

    /*
    |--------------------------------------------------------------------------
    | First Run DB Setup
    |--------------------------------------------------------------------------
    */
    if (!file_exists(STORAGE_PATH . '/data.sqlite')) {
        require CORE_PATH . '/bootstrap/db_setup.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Little Helpers
    |--------------------------------------------------------------------------
    */
    require CORE_PATH . '/helpers/common.php';
    require CORE_PATH . '/helpers/cache.php';
    require CORE_PATH . '/helpers/content.php';
    require CORE_PATH . '/helpers/menus.php';
    require CORE_PATH . '/helpers/settings.php';
    require CORE_PATH . '/helpers/sitemap.php';

    /*
    |--------------------------------------------------------------------------
    | Core Systems
    |--------------------------------------------------------------------------
    */
    require CORE_PATH . '/auth.php';
    require CORE_PATH . '/db.php';
    require CORE_PATH . '/render.php';
    require CORE_PATH . '/router.php';

    /*
    |--------------------------------------------------------------------------
    | Dispatch to Mode-specific Logic
    |--------------------------------------------------------------------------
    */
    switch ($mode) {
        case 'frontend':
            handle_frontend_request($config);
            break;

        case 'admin':
            handle_admin_request();
            break;

        default:
            http_response_code(404);
            echo "<h1>404 – Unknown mode</h1>";
            break;
    }
}

function handle_frontend_request($config): void
{
    // Cache setup
    define('CACHE_LIFETIME', $config['cache_lifetime']);
    
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    $key = trim($path, '/') ?: 'home';
    $cacheFile = STORAGE_PATH . '/cache/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.html';

    // Serve cached page if valid
    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && file_exists($cacheFile)
        && (time() - filemtime($cacheFile) < CACHE_LIFETIME)
    ) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=' . CACHE_LIFETIME);
        header('X-Cache: HIT');
        echo file_get_contents($cacheFile);
        exit;
    }

    // Resolve page and render
    $page = route_request();
    $response = render_page($page);

    http_response_code($response['status'] ?? 200);
    foreach ($response['headers'] ?? [] as $header) header($header);
    echo $response['body'];

    // Cache successful GET responses
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($response['status'] ?? 200) === 200) {
        $temp = $cacheFile . '.tmp';
        file_put_contents($temp, $response['body']);
        rename($temp, $cacheFile);
    }
}

function handle_admin_request(): void
{
    // Start session if needed
    if (session_status() === PHP_SESSION_NONE) session_start();

    // Check session timeout
    session_timeout_check();

    // Dispatch to admin router
    route_admin_request();
}

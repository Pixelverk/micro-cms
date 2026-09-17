<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Front-end bootstrap
|--------------------------------------------------------------------------
|
| Cached and fresh paths. Two rules keep the HTML cache honest:
|
|  * only anonymous, non-preview GETs may be cached, and
|  * a cached file is never served to a signed-in user.
|
| The second rule matters because a cache file is keyed by path alone: an
| editor's preview of /about/ would otherwise be published to every visitor.
|
*/

/**
 * Path of the cache file for a request path.
 */
function cache_file_for(string $request): string
{
    $key = trim($request, '/') ?: 'home';

    return STORAGE_PATH . '/cache/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.html';
}

function checkCache($request, $config)
{
    // Search results depend on the query string, which is not part of the
    // cache key, so they are never served from it.
    if (str_starts_with(trim($request, '/'), 'search')) {
        return false;
    }

    // Nothing cached may be served to anyone with an identity, and a preview
    // request must always be rendered live. checkCache() runs before the
    // helper set is loaded, so probe for the helper rather than assuming it.
    if (!empty($_SESSION['user_id'])) {
        return false;
    }

    if (function_exists('can_preview_content') && can_preview_content()) {
        return false;
    }

    $cacheFile = cache_file_for($request);

    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && file_exists($cacheFile)
        && (time() - filemtime($cacheFile) < $config['cache_lifetime'])
    ) {
        return $cacheFile;
    }

    return false;
}

function serveCached($file, $config)
{
    // helpers — the same set the fresh path uses, because the shutdown hook
    // below calls publishing_check(), which needs the content helpers.
    require_once CORE_PATH . '/helpers/common.php';
    bootstrap_core();

    // Start session
    session_boot();

    // check for scheduled content items after request is done
    register_shutdown_function('publishing_check');

    // Serve cached page if valid
    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && file_exists($file)
        && (time() - filemtime($file) < $config['cache_lifetime'])
    ) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=' . $config['cache_lifetime']);
        header('X-Cache: HIT');
        echo file_get_contents($file);
    }
}

function serveFresh($request)
{
    // helpers
    require_once CORE_PATH . '/helpers/common.php';
    bootstrap_core();

    // Core Systems
    require CORE_PATH . '/render.php';
    require CORE_PATH . '/router.php';

    // Start session
    session_boot();

    // Upgrade the schema before rendering (and explain failures clearly).
    migrate_before_read();

    // check for scheduled content items after request is done
    register_shutdown_function('publishing_check');

    // Resolve page and render
    $page = route_request($request);
    $response = render_page($page);

    // A preview is rendered live and labelled as such.
    if (is_preview_request()) {
        header('X-Preview: 1');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    http_response_code($response['status'] ?? 200);
    foreach ($response['headers'] ?? [] as $header) header($header);
    echo $response['body'];

    // Cache successful anonymous GETs only. Drafts, archives and anything
    // produced for a signed-in user stay out of the shared cache.
    $isArchivePage = isset($page['taxonomy']);
    // Query-driven views (search) must never be written to the shared cache.
    $isQueryView = !empty($page['no_cache']);

    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && ($response['status'] ?? 200) === 200
        && ($page['status'] ?? '') === 'published'
        && !response_is_uncacheable()
        && !$isArchivePage
        && !$isQueryView
    ) {
        $cacheFile = cache_file_for($request);
        $temp = $cacheFile . '.tmp';

        file_put_contents($temp, $response['body']);
        rename($temp, $cacheFile);
    }
}

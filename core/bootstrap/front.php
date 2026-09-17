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
| Both functions assume the front entry point (index.php) has already run
| bootstrap_core() and migrate_before_read(), and has started the session when
| the request carries one. That order is owned there on purpose: the cache
| decision needs the helper set and the session state, and the schema must be
| current before anything reads content.
|
*/

/**
 * Path of the cache file for a request path (see core/helpers/cache.php).
 */

function checkCache($request, $config)
{
    // Search results depend on the query string, which is not part of the
    // cache key, so they are never served from it.
    if (str_starts_with(trim($request, '/'), 'search')) {
        return false;
    }

    // Nothing cached may be served to anyone with an identity, and a preview
    // request must always be rendered live.
    if (!empty($_SESSION['user_id'])) {
        return false;
    }

    if (can_preview_content()) {
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
    // check for scheduled content items after request is done
    register_shutdown_function('publishing_check');
    register_shutdown_function('content_maybe_purge_trash');

    // checkCache() already validated the method, the file and its age.
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=' . $config['cache_lifetime']);
    header('X-Cache: HIT');
    echo file_get_contents($file);

    // Cache hits are the common case, so they count as views too. checkCache()
    // has already ruled out signed-in and preview requests.
    analytics_record_view(null, true);
}

function serveFresh($request)
{
    // Rendering only happens on this path, so a cache hit never loads these.
    require CORE_PATH . '/render.php';
    require CORE_PATH . '/router.php';

    // check for scheduled content items after request is done
    register_shutdown_function('publishing_check');
    register_shutdown_function('content_maybe_purge_trash');

    // A redirect wins before routing, so an old URL never falls through to a
    // 404. Saving a redirect clears that path's cache file, so it also beats the
    // firebreak in index.php without a database read on every cache hit.
    $redirect = redirect_find($request);

    if ($redirect) {
        redirect_record_hit($redirect['from_path']);
        redirect_send($redirect);
    }

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
        && !can_preview_content()
        && !$isArchivePage
        && !$isQueryView
    ) {
        cache_write($request, $response['body']);
    }

    // Traffic counting is deferred to a shutdown write. Signed-in users and
    // token previews are not visitor traffic, and 404s are kept so the
    // redirects page can suggest catching them.
    $status = (int) ($response['status'] ?? 200);

    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && in_array($status, [200, 404], true)
        && !is_logged_in()
        && !can_preview_content()
    ) {
        analytics_record_view(isset($page['id']) ? (int) $page['id'] : null, false, $status);
    }
}

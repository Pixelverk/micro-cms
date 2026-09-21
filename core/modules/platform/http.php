<?php
declare(strict_types=1);



/*
|--------------------------------------------------------------------------
| HTML Escaping
|--------------------------------------------------------------------------
*/

function e(string|int|null $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}


/*
|--------------------------------------------------------------------------
| SQL Helpers
|--------------------------------------------------------------------------
*/

/**
 * Escape the wildcards in a value about to be placed inside a LIKE.
 *
 * Pair it with `ESCAPE '\'` on the clause. Without this, a visitor typing "%"
 * or "_" turns their search into a pattern — `%%` matched the whole site.
 */
function like_escape(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}


/*
|--------------------------------------------------------------------------
| Request Helpers
|--------------------------------------------------------------------------
*/

/**
 * Is this request from fetch()/XHR and expecting a JSON error payload?
 *
 * Shared by the CSRF and validation abort paths so both agree on what counts
 * as a JSON caller.
 */
function request_wants_json(): bool
{
    return !empty($_POST['_json'])
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || (($_SERVER['HTTP_ACCEPT'] ?? '') !== '' && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json'));
}


/**
 * Generate a full URL for the site, respecting subfolder deployment.
 *
 * Examples:
 *   url()                  => "/" or "/subfolder/"
 *   url("admin/dashboard") => "/admin/dashboard/" or "/subfolder/admin/dashboard/"
 *
 * @param string $path Relative path or file
 * @return string
 */
function url(string $path = ''): string
{
    global $config;

    // Base URL from config — set '' for root, '/subfolder' if deployed in a subfolder
    $baseUrl = rtrim($config['url'] ?? '', '/');

    // Remove leading slash to avoid double slashes
    $path = ltrim($path, '/');

    // If no path, just return base URL with trailing slash
    if ($path === '') {
        return $baseUrl === '' ? '/' : $baseUrl . '/';
    }

    // Detect files by extension (don't add trailing slash)
    $isFile = pathinfo($path, PATHINFO_EXTENSION) !== '';

    // Compose URL
    $url = $baseUrl === '' ? '/' . $path : $baseUrl . '/' . $path;

    // Add trailing slash if it's a directory
    if (!$isFile) {
        $url = rtrim($url, '/') . '/';
    }

    return $url;
}


/**
 * URL-safe slug.
 *
 * Transliterates first, so a non-ASCII label keeps its letters ("Smörgås"
 * becomes "smorgas") instead of losing them. Returns '' when nothing usable
 * is left; callers that need a fallback choose their own.
 */
function sanitize_slug(string $slug): string
{
    $transliterated = @iconv('utf-8', 'us-ascii//TRANSLIT', $slug);

    if ($transliterated !== false) {
        $slug = $transliterated;
    }

    $slug = strtolower($slug);
    $slug = preg_replace('/[\s_]+/', '-', $slug);
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    $slug = preg_replace('/-+/', '-', $slug);

    return trim($slug, '-');
}


// Debug log message
function debug_log(string $msg): void {
    $file = STORAGE_PATH . '/logs/debug.log';
    $time = date('Y-m-d H:i:s');
    file_put_contents($file, "[{$time}] {$msg}\n", FILE_APPEND);
}



/*
|--------------------------------------------------------------------------
| Admin redirect helpers
|--------------------------------------------------------------------------
*/
function redirect(string $path): void
{   
    header('Location: ' . url('admin/' . $path));
    exit;
}


function redirect_with_toast(
    string $path,
    string $type,
    string $message,
    array $query = []
): void {
    $_SESSION['toast'] = [
        'type'    => $type,
        'message' => $message,
    ];

    $location = url('admin/' . trim($path, '/'));

    if ($query) {
        $location .= '?' . http_build_query($query);
    }

    header('Location: ' . $location);
    exit;
}

/*
|--------------------------------------------------------------------------
| Absolute site URLs
|--------------------------------------------------------------------------
| The site's origin and the absolute form of a site-relative URL. Used by SEO
| tags, the sitemap, robots, form/notification links and the static export, so
| they live here rather than in the SEO module.
*/

/**
 * The site's canonical origin, e.g. https://example.com (no trailing slash).
 *
 * Prefers the `site_url` setting, then the CMS `url` config, then the request.
 */
function site_origin(): string
{
    static $cached = null;

    if (is_string($cached)) {
        return $cached;
    }

    $configured = trim((string) get_setting('site_url', ''));

    if ($configured === '') {
        // config('url') is a path prefix for subfolder installs, not an origin,
        // so it is only usable when it is already absolute.
        $configUrl = trim((string) config('url', ''));

        if (preg_match('#^https?://#i', $configUrl)) {
            $configured = $configUrl;
        }
    }

    if ($configured === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https'
            : 'http';

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Never trust a Host header containing anything but a hostname/port.
        if (!preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', $host)) {
            $host = 'localhost';
        }

        $configured = $scheme . '://' . $host;
    }

    return $cached = rtrim($configured, '/');
}

/**
 * Absolute form of a site-relative URL (or pass an absolute one through).
 */
function absolute_url(string $url): string
{
    if ($url === '') {
        return site_origin() . '/';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    return site_origin() . '/' . ltrim($url, '/');
}

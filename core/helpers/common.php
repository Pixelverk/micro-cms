<?php
declare(strict_types=1);

// Entry points load this file with a plain require in places, so guard against
// being included twice (which would redeclare every function below).
if (function_exists('config')) {
    return;
}

/*
|--------------------------------------------------------------------------
| Config Access
|--------------------------------------------------------------------------
*/

function config(string $key, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        // CMS_CONFIG_FILE lets test runs point at their own config without
        // touching the tracked config.php. Falls back to the real one.
        $configFile = getenv('CMS_CONFIG_FILE') ?: CMS_PATH . '/config.php';
        $config = require $configFile;
    }

    $segments = explode('.', $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

/*
|--------------------------------------------------------------------------
| Session boot
|--------------------------------------------------------------------------
*/

/**
 * Start the session with hardened defaults.
 *
 * Session files live in storage/sessions so the app does not depend on the
 * host's (often read-only) PHP session directory. Tests point this elsewhere
 * by defining CMS_SESSION_PATH before boot.
 */
function session_boot(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $sessionPath = defined('CMS_SESSION_PATH')
        ? CMS_SESSION_PATH
        : STORAGE_PATH . '/sessions';

    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0775, true);
    }

    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_start();
}

/*
|--------------------------------------------------------------------------
| Core bootstrap
|--------------------------------------------------------------------------
*/

/**
 * Load the shared helper set once per request.
 *
 * Safe to call from every entry point (admin, front, tests) — repeated calls
 * are a no-op. `$withContent` pulls in the heavier content/taxonomy helpers
 * that the admin area needs and the cached front-end path does not.
 */
function bootstrap_core(bool $withContent = true): void
{
    // Two flags, because this file can be included before bootstrap_core() runs:
    //  * cms_boot_started    — we are inside this function
    //  * cms_boot_loaded     — the helper set is actually on disk
    if (!empty($GLOBALS['cms_boot_loaded'])) {
        return;
    }

    $GLOBALS['cms_boot_started'] = true;

    require_once CORE_PATH . '/helpers/cache.php';
    require_once CORE_PATH . '/helpers/settings.php';
    require_once CORE_PATH . '/helpers/admin.php';
    require_once CORE_PATH . '/helpers/icons.php';
    require_once CORE_PATH . '/helpers/sitemap.php';
    require_once CORE_PATH . '/helpers/csrf.php';
    require_once CORE_PATH . '/helpers/validate.php';
    require_once CORE_PATH . '/helpers/throttle.php';
    require_once CORE_PATH . '/helpers/migrate.php';
    require_once CORE_PATH . '/helpers/activity.php';
    require_once CORE_PATH . '/helpers/seo.php';

    if ($withContent) {
        require_once CORE_PATH . '/helpers/content.php';
        require_once CORE_PATH . '/helpers/menus.php';
        require_once CORE_PATH . '/helpers/publishing.php';
        require_once CORE_PATH . '/helpers/versions.php';
        require_once CORE_PATH . '/helpers/search.php';
    }

    require_once CORE_PATH . '/db.php';

    // User functions (current_user, is_logged_in) are needed by capability
    // checks on BOTH entry points. The front end used to omit this file, so
    // can_preview_content() could never resolve a signed-in user.
    require_once CORE_PATH . '/auth.php';

    $GLOBALS['cms_boot_loaded'] = true;
}

/*
|--------------------------------------------------------------------------
| Theme Helpers
|--------------------------------------------------------------------------
*/

function theme(string $path = ''): string
{
    $base = CMS_PATH . '/theme';
    return $path ? $base . '/' . ltrim($path, '/') : $base;
}

function theme_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $themeFile = theme('theme.php');

    if (!file_exists($themeFile)) {
        throw new RuntimeException("Theme config not found at {$themeFile}");
    }

    $config = require $themeFile;

    if (!is_array($config)) {
        throw new RuntimeException("Theme config at {$themeFile} must return an array");
    }

    return $config;
}

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

/**
 * Return a URL to a theme asset, respecting base URL and subfolder.
 * External assets are identified by https.
 */
function asset(string $path): string
{
    if (preg_match('#^(?:https?:)?//#i', $path)) {
        return $path;
    }

    return url("theme/assets/" . ltrim($path, '/'));
}

/**
 * Return a URL to a theme image, respecting base URL and subfolder.
 */
function img(string $path): string
{
    return url("theme/assets/img/" . ltrim($path, '/'));
}

// Debug log message
function debug_log(string $msg): void {
    $file = STORAGE_PATH . '/logs/debug.log';
    $time = date('Y-m-d H:i:s');
    file_put_contents($file, "[{$time}] {$msg}\n", FILE_APPEND);
}


// login status? 
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login');
        exit;
    }
}

// timezone convert
function format_local_datetime(?int $timestamp, string $format = 'Y-m-d H:i'): string
{
    if (!$timestamp) {
        return '—';
    }

    $dt = new DateTime('@' . $timestamp); // UTC
    $dt->setTimezone(new DateTimeZone(SITE_TIMEZONE));

    return $dt->format($format);
}

/*
|--------------------------------------------------------------------------
| Media helpers
|--------------------------------------------------------------------------
| A media row stores a `base_path` (e.g. 2026/03/abc123) and a `formats_json`
| map of format => [relative variant paths]. Everything a theme needs is
| derived from those two, so no column is read speculatively.
|--------------------------------------------------------------------------
*/

/**
 * Recursively delete a media folder and everything inside it.
 *
 * Used when media is removed and when a replacement upload supersedes an
 * existing file's folder. Callers validate the path first.
 */
function delete_media_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path)) {
            delete_media_directory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

/**
 * Load a media row once per request.
 */
function media_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    static $cache = [];

    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    $stmt = db()->prepare("SELECT * FROM media WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);

    return $cache[$id] = ($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
}

/**
 * Decoded formats map: ['webp' => ['path1', ...], 'jpg' => [...]].
 *
 * @return array<string, list<string>>
 */
function media_formats(array $media): array
{
    $formats = json_decode((string) ($media['formats_json'] ?? ''), true);

    return is_array($formats) ? $formats : [];
}

/**
 * Public URL for a media row, optionally the variant nearest a width.
 *
 * Non-image media (PDF, MP4, …) only has the original, which formats_json
 * still records, so this works for every media type.
 */
function media_url(int $id, ?int $width = null, ?string $format = null): string
{
    $media = media_by_id($id);

    if (!$media) {
        return '';
    }

    $formats = media_formats($media);

    $chosen = null;

    if ($format !== null && !empty($formats[$format])) {
        $chosen = $formats[$format];
    } else {
        foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $candidate) {
            if (!empty($formats[$candidate])) {
                $chosen = $formats[$candidate];
                break;
            }
        }
    }

    // Fall back to whatever the first format recorded (covers pdf, mp4, …).
    if ($chosen === null) {
        foreach ($formats as $paths) {
            if (!empty($paths)) {
                $chosen = $paths;
                break;
            }
        }
    }

    // A row with no recorded variants at all: rebuild the original path.
    if ($chosen === null) {
        $original = (string) ($media['original_name'] ?? '');

        if ($original === '' || empty($media['base_path'])) {
            return '';
        }

        $base = sanitize_slug(pathinfo($original, PATHINFO_FILENAME));
        $ext  = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        return $ext === '' ? '' : url("media/{$media['base_path']}/{$base}.{$ext}");
    }

    // Pick the variant whose width token is closest to the request.
    $best = null;
    $bestDistance = PHP_INT_MAX;

    foreach ($chosen as $path) {
        $variantWidth = null;

        if (preg_match('/-(\d+)\.[a-z0-9]+$/i', $path, $matches)) {
            $variantWidth = (int) $matches[1];
        }

        if ($width === null || $variantWidth === null) {
            $best = $best ?? $path;
            continue;
        }

        $distance = abs($variantWidth - $width);

        if ($distance < $bestDistance) {
            $best = $path;
            $bestDistance = $distance;
        }
    }

    $best = $best ?? reset($chosen);

    return $best ? url('media/' . $best) : '';
}

/**
 * Is this media row renderable as an image?
 */
function media_is_image(array $media): bool
{
    return str_starts_with((string) ($media['mime_type'] ?? ''), 'image/');
}

/**
 * A <picture> element (WebP source + LQIP background) for an uploaded image.
 * Returns '' for missing rows, non-images, or rows without variants.
 */
function picture(int $id, array $attrs = []): string
{
    $media = media_by_id($id);

    if (!$media) {
        return '';
    }

    if (!media_is_image($media)) {
        debug_log("picture() called with non-image media #{$id} ({$media['mime_type']}); use media_url() instead");

        return '';
    }

    $formats = media_formats($media);

    if (!$formats) {
        return '';
    }

    $width  = (int) ($media['width'] ?? 0);
    $height = (int) ($media['height'] ?? 0);
    $lqip   = (string) ($media['lqip_base64'] ?? '');

    /**
     * Variant paths -> [width => URL], ordered by width.
     */
    $buildSrcset = function (array $paths) use ($width): array {
        $items = [];

        foreach ($paths as $path) {
            if (preg_match('/-(\d+)\.[a-z0-9]+$/i', $path, $matches)) {
                $items[(int) $matches[1]] = url('media/' . $path);
            } else {
                // No width token in the filename: key it by the original width.
                $items[$width ?: 0] = url('media/' . $path);
            }
        }

        ksort($items);

        return $items;
    };

    // Fallback format: the first non-webp entry.
    $fallbackFormat = null;
    foreach (array_keys($formats) as $format) {
        if ($format !== 'webp') {
            $fallbackFormat = $format;
            break;
        }
    }

    if ($fallbackFormat === null) {
        return '';
    }

    $fallbackSet = $buildSrcset($formats[$fallbackFormat] ?? []);

    if (!$fallbackSet) {
        return '';
    }

    $fallbackSrc    = (string) reset($fallbackSet);
    $fallbackSrcset = implode(', ', array_map(
        fn($src, $w) => "{$src} {$w}w",
        $fallbackSet,
        array_keys($fallbackSet)
    ));

    $webpSet    = !empty($formats['webp']) ? $buildSrcset($formats['webp']) : [];
    $webpSrcset = implode(', ', array_map(
        fn($src, $w) => "{$src} {$w}w",
        $webpSet,
        array_keys($webpSet)
    ));

    // Defaults; callers can override any of them.
    $attrs['loading'] ??= 'lazy';
    $attrs['alt'] ??= (string) ($media['alt_text'] ?? '');

    if ($width && $height) {
        $attrs['width'] ??= $width;
        $attrs['height'] ??= $height;
    }

    $attrString = '';
    foreach ($attrs as $name => $value) {
        $attrString .= ' ' . e($name) . '="' . e((string) $value) . '"';
    }

    // Start at the smallest variant; main.js refines this to the real
    // container width once the image is on screen.
    $smallestWidth = (int) array_key_first($fallbackSet);
    $initialSizes  = $smallestWidth ? $smallestWidth . 'px' : '100vw';

    $html = '<div class="image-wrapper"';
    $html .= $lqip ? ' style="background-image:url(' . e($lqip) . ');">' : '>';
    $html .= '<picture>';

    if ($webpSet) {
        $html .= '<source type="image/webp" srcset="' . e($webpSrcset) . '" sizes="' . e($initialSizes) . '">';
    }

    $html .= '<img src="' . e($fallbackSrc) . '" srcset="' . e($fallbackSrcset) . '" sizes="' . e($initialSizes) . '"' . $attrString . '>';
    $html .= '</picture>';
    $html .= '</div>';

    return $html;
}

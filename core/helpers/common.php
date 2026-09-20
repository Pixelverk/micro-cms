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
| Security headers
|--------------------------------------------------------------------------
|
| A conservative baseline sent with every response. No Content-Security-Policy
| is set here on purpose: the admin boots from inline scripts and the Settings
| screen deliberately allows raw header/footer snippets, so a useful policy
| would need either nonce plumbing or 'unsafe-inline'. See plan.md phase 6.
*/

/**
 * The security headers for this request, name => value.
 *
 * Split from the sending so the HTTPS-only HSTS rule can be tested without a
 * TLS server.
 *
 * @return array<string, string>
 */
function security_headers(): array
{
    $headers = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
    ];

    // HSTS is a one-year commitment for this host, so it is only sent over TLS.
    // includeSubDomains is deliberately omitted: a sibling host still on plain
    // HTTP would be broken by it.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    if ($https) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000';
    }

    return $headers;
}

/**
 * Send the baseline. Called from the entry point so every response (front,
 * admin, media, redirects, error pages) carries it.
 */
function send_security_headers(): void
{
    foreach (security_headers() as $name => $value) {
        header($name . ': ' . $value);
    }
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
    require_once CORE_PATH . '/helpers/pagination.php';
    require_once CORE_PATH . '/helpers/settings.php';
    require_once CORE_PATH . '/helpers/admin.php';
    require_once CORE_PATH . '/helpers/icons.php';
    require_once CORE_PATH . '/helpers/sitemap.php';
    require_once CORE_PATH . '/helpers/robots.php';
    require_once CORE_PATH . '/helpers/csrf.php';
    require_once CORE_PATH . '/helpers/validate.php';
    require_once CORE_PATH . '/helpers/throttle.php';
    require_once CORE_PATH . '/helpers/migrate.php';
    require_once CORE_PATH . '/helpers/activity.php';
    require_once CORE_PATH . '/helpers/analytics.php';
    require_once CORE_PATH . '/helpers/health.php';
    require_once CORE_PATH . '/helpers/redirects.php';
    require_once CORE_PATH . '/helpers/zip.php';
    require_once CORE_PATH . '/helpers/seo.php';

    if ($withContent) {
        require_once CORE_PATH . '/helpers/content.php';
        require_once CORE_PATH . '/helpers/forms.php';
        require_once CORE_PATH . '/helpers/menus.php';
        require_once CORE_PATH . '/helpers/publishing.php';
        require_once CORE_PATH . '/helpers/versions.php';
        require_once CORE_PATH . '/helpers/search.php';
        require_once CORE_PATH . '/helpers/export.php';
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
 * Stamp an asset URL with the file's modification time.
 *
 * Editing the file changes its URL, so a browser holding the old one fetches
 * the new copy instead of serving a stale stylesheet or script from its cache.
 * A file that is not there is left unstamped: a version that points at nothing
 * helps no one.
 */
function version_asset_url(string $url, string $file): string
{
    return is_file($file) ? $url . '?v=' . filemtime($file) : $url;
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

    // A query string is not part of the file name. Dropping it means a
    // hand-written ?v= is replaced by the stamp rather than appended to.
    $relative = ltrim(explode('?', $path, 2)[0], '/');

    return version_asset_url(
        url('theme/assets/' . $relative),
        CMS_PATH . '/theme/assets/' . $relative
    );
}

/**
 * Return a URL to a theme image, respecting base URL and subfolder.
 */
function img(string $path): string
{
    return url("theme/assets/img/" . ltrim($path, '/'));
}

/**
 * Resolve a media id, an absolute URL, or a theme image filename to a URL.
 *
 * The shape every image setting accepts. Callers that need an absolute URL
 * (Open Graph, JSON-LD) pass the result through seo_absolute_url().
 */
function resolve_image_value(string $value, ?int $width = null): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (ctype_digit($value)) {
        return media_url((int) $value, $width);
    }

    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    return img($value);
}

/**
 * Render an image value in a template.
 *
 * An image value is a media id, an absolute URL, or a theme filename. A media
 * id gets the responsive picture() block (WebP srcset, LQIP, alt from the media
 * row). A filename or URL gets a plain <img>, exactly the markup the theme used
 * before, so it is never wrapped in picture()'s LQIP wrapper — main.js only
 * un-blurs `.image-wrapper picture img`, and a bare <img> there would stay
 * invisible.
 */
function render_image(mixed $value, array $attrs = []): string
{
    $value = is_scalar($value) ? trim((string) $value) : '';

    if ($value === '') {
        return '';
    }

    if (ctype_digit($value)) {
        $picture = picture((int) $value, $attrs);

        if ($picture !== '') {
            return $picture;
        }

        // picture() needs recorded variants. A row without them (an upload the
        // generator could not encode, or an older import) still has its original
        // file, and an editor who chose that image should not get nothing.
        $media = media_by_id((int) $value);

        if ($media === null || !media_is_image($media)) {
            return '';
        }

        $url = media_url((int) $value);
    } else {
        $url = resolve_image_value($value);
    }

    if ($url === '') {
        return '';
    }

    $attrs['src'] = $url;

    $attrString = '';
    foreach ($attrs as $name => $attrValue) {
        $attrString .= ' ' . e($name) . '="' . e((string) $attrValue) . '"';
    }

    return '<img' . $attrString . '>';
}

/**
 * The site logo: the Settings value, else the theme manifest, else nothing.
 */
function site_logo_url(): string
{
    $value = trim((string) get_setting('logo', ''));

    if ($value !== '') {
        return resolve_image_value($value);
    }

    $fallback = (string) (theme_config()['icons']['logo'] ?? '');

    return $fallback !== '' ? asset($fallback) : '';
}

/**
 * The favicon: the Settings value, else the theme manifest, else nothing.
 */
function site_favicon_url(): string
{
    $value = trim((string) get_setting('favicon', ''));

    if ($value !== '') {
        return resolve_image_value($value);
    }

    $fallback = (string) (theme_config()['icons']['favicon'] ?? '');

    return $fallback !== '' ? asset($fallback) : '';
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
    $dt->setTimezone(new DateTimeZone(site_timezone()));

    return $dt->format($format);
}

/**
 * The site timezone, from Settings, falling back to the shipped default.
 */
function site_timezone(): string
{
    $timezone = (string) get_setting('timezone', 'Europe/Stockholm');

    return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Europe/Stockholm';
}

/**
 * Format a timestamp for the public site, using the configured date format.
 *
 * Unlike format_local_datetime() this returns an empty string rather than a
 * placeholder, because theme templates already guard against a missing date.
 */
function format_date(?int $timestamp, ?string $format = null): string
{
    if (!$timestamp) {
        return '';
    }

    $format = $format ?? (string) get_setting('date_format', 'F j, Y');

    $dt = new DateTime('@' . $timestamp); // UTC
    $dt->setTimezone(new DateTimeZone(site_timezone()));

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
 * Where every media file is referenced, keyed by media id.
 *
 * One pass over content, settings and menus, so a page can show what a delete
 * would break without a query per file. A reference is found in two ways:
 *
 *  - **by id**, but only where an id is what gets stored: image-typed component
 *    props and the image settings. Matching any number anywhere would collide
 *    with ordinary values such as a WebP quality of 80 or a `limit` of 3.
 *  - **by path**, anywhere at all: a media URL pasted into rich text, a menu
 *    link, a setting. A base path is a unique `YYYY/MM/random` folder name, so
 *    finding it inside a longer string is unambiguous.
 *
 * Saved versions and form submissions are not scanned: they are history rather
 * than something the current site renders.
 *
 * @return array<int, list<string>> media id => labels of the places using it
 */
function media_usage_map(): array
{
    $usage = [];

    // Base path => id, for turning a path reference back into a media row.
    $idsByPath = [];

    foreach (db()->query("SELECT id, base_path FROM media") as $row) {
        $idsByPath[(string) $row['base_path']] = (int) $row['id'];
    }

    if ($idsByPath === []) {
        return $usage;
    }

    /**
     * Record a label against a media id, once.
     */
    $note = static function (int $id, string $label) use (&$usage): void {
        if (!in_array($label, $usage[$id] ?? [], true)) {
            $usage[$id][] = $label;
        }
    };

    /**
     * Record an id that is stored as-is. Only call this for fields where a
     * number really is a media id.
     */
    $noteId = static function (mixed $value, string $label) use ($note): void {
        if (is_scalar($value) && ctype_digit(trim((string) $value))) {
            $note((int) trim((string) $value), $label);
        }
    };

    /**
     * Record any media URLs or paths found inside a value of any shape.
     */
    $notePaths = static function (mixed $value, string $label) use ($idsByPath, $note, &$notePaths): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                $notePaths($item, $label);
            }

            return;
        }

        if (!is_scalar($value)) {
            return;
        }

        if (preg_match_all('#(\d{4}/\d{2}/[A-Za-z0-9]+)#', (string) $value, $matches)) {
            foreach (array_unique($matches[1]) as $candidate) {
                if (isset($idsByPath[$candidate])) {
                    $note($idsByPath[$candidate], $label);
                }
            }
        }
    };

    // Content: image props by id, anything at all by path.
    $contentTypes = theme_config()['content_types'] ?? [];

    $rows = db()->query("SELECT type, title, meta, body FROM content WHERE deleted_at IS NULL")->fetchAll();

    foreach ($rows as $row) {
        $type  = (string) $row['type'];
        $label = ($contentTypes[$type]['label'] ?? ucfirst($type)) . ' “' . (string) $row['title'] . '”';

        $meta = json_decode((string) $row['meta'], true);
        $meta = is_array($meta) ? $meta : [];
        $body = json_decode((string) $row['body'], true);
        $body = is_array($body) ? $body : [];

        // The meta keys a content type stores an image in: the ones it declares
        // for the editor, plus the older ones a layout reads directly.
        $imageMetaKeys = array_unique(array_merge(
            array_keys($contentTypes[$type]['images'] ?? []),
            ['thumbnail', 'image', 'author_image', 'gallery', 'og_image']
        ));

        foreach ($imageMetaKeys as $key) {
            foreach ((array) ($meta[$key] ?? []) as $value) {
                $noteId($value, $label);
            }
        }

        $notePaths($meta, $label);
        $notePaths($body, $label);

        // Component props: only a field the schema calls an image holds an id.
        $walk = static function (array $components) use (&$walk, $noteId, $label): void {
            foreach ($components as $component) {
                if (!is_array($component)) {
                    continue;
                }

                $name  = (string) ($component['type'] ?? '');
                $props = is_array($component['props'] ?? null) ? $component['props'] : [];

                if ($name !== '') {
                    $schema = content_component_definition($name)['schema'] ?? [];

                    foreach ($schema as $field => $rules) {
                        if (is_array($rules) && ($rules['type'] ?? '') === 'image') {
                            $noteId($props[$field] ?? null, $label);
                        }
                    }
                }

                $walk(is_array($component['children'] ?? null) ? $component['children'] : []);
            }
        };

        $walk($body);
    }

    // Settings: only the fields that hold an image can hold a media id.
    $settings = load_settings();

    foreach (['logo', 'favicon', 'default_og_image'] as $key) {
        $noteId($settings[$key] ?? null, 'Site settings (' . $key . ')');
    }

    $notePaths(array_filter($settings, 'is_scalar'), 'Site settings');

    // Menus only ever store URLs, so paths are the only thing to match.
    foreach (list_menus() as $menu) {
        $notePaths($menu['items'] ?? [], 'Menu “' . (string) $menu['label'] . '”');
    }

    return $usage;
}

/**
 * Delete one media row and the folder holding its files.
 *
 * Returns 'deleted', 'not_found' or 'invalid_path'. The folder goes first: a
 * row left behind would render a broken image on the site, while a folder left
 * behind is only wasted bytes. The path is resolved against the media directory
 * so a crafted base_path can never reach outside storage.
 */
function media_delete(int $id): string
{
    $pdo = db();

    $stmt = $pdo->prepare("SELECT base_path FROM media WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $basePath = $stmt->fetchColumn();

    if ($basePath === false) {
        return 'not_found';
    }

    $mediaRoot = realpath(STORAGE_PATH . '/media');
    $folder    = $mediaRoot === false ? false : realpath($mediaRoot . '/' . $basePath);

    if ($folder === false || !str_starts_with($folder, $mediaRoot)) {
        return 'invalid_path';
    }

    delete_media_directory($folder);

    // The uploader creates YYYY/MM folders; remove them once they are empty.
    $dir = dirname($folder);
    while ($dir !== $mediaRoot && is_dir($dir) && count(scandir($dir)) === 2) {
        @rmdir($dir);
        $dir = dirname($dir);
    }

    $pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$id]);

    return 'deleted';
}

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

    // Fallback format: the first non-webp entry. An upload that was already
    // webp has no other format, so its webp set is the fallback too — otherwise
    // a webp-only image would render nothing.
    $fallbackFormat = null;
    foreach (array_keys($formats) as $format) {
        if ($format !== 'webp') {
            $fallbackFormat = $format;
            break;
        }
    }

    $fallbackFormat ??= 'webp';

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

    // The webp source only adds a choice when the fallback is another format.
    $webpSet    = ($fallbackFormat === 'webp' || empty($formats['webp'])) ? [] : $buildSrcset($formats['webp']);
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

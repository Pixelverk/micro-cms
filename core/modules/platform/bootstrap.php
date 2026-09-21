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
| would need either nonce plumbing or 'unsafe-inline'. See the CSP entry in
| plan.md's backlog.
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
function bootstrap_core(): void
{
    // Two flags, because this file can be included before bootstrap_core() runs:
    //  * cms_boot_started    — we are inside this function
    //  * cms_boot_loaded     — the helper set is actually on disk
    if (!empty($GLOBALS['cms_boot_loaded'])) {
        return;
    }

    $GLOBALS['cms_boot_started'] = true;

    // platform — the foundation every other module may call.
    require_once CORE_PATH . '/modules/platform/db.php';
    require_once CORE_PATH . '/modules/platform/settings.php';
    require_once CORE_PATH . '/modules/platform/maintenance.php';
    require_once CORE_PATH . '/modules/platform/cache.php';
    require_once CORE_PATH . '/modules/platform/migrate.php';
    require_once CORE_PATH . '/modules/platform/validate.php';
    require_once CORE_PATH . '/modules/platform/throttle.php';
    require_once CORE_PATH . '/modules/platform/csrf.php';
    require_once CORE_PATH . '/modules/platform/activity.php';
    require_once CORE_PATH . '/modules/platform/analytics.php';
    require_once CORE_PATH . '/modules/platform/pagination.php';
    require_once CORE_PATH . '/modules/platform/zip.php';
    require_once CORE_PATH . '/modules/platform/theme.php';
    require_once CORE_PATH . '/modules/platform/taxonomies.php';
    require_once CORE_PATH . '/modules/platform/http.php';
    require_once CORE_PATH . '/modules/platform/datetime.php';
    // User functions (current_user, is_logged_in) are needed by capability
    // checks on BOTH entry points. The front end used to omit this file, so
    // can_preview_content() could never resolve a signed-in user.
    require_once CORE_PATH . '/modules/platform/auth.php';
    require_once CORE_PATH . '/modules/platform/preview.php';
    require_once CORE_PATH . '/modules/platform/access.php';
    require_once CORE_PATH . '/modules/platform/i18n.php';

    // media
    require_once CORE_PATH . '/modules/media/media.php';
    require_once CORE_PATH . '/modules/media/upload.php';

    // admin shell
    require_once CORE_PATH . '/modules/admin/admin.php';
    require_once CORE_PATH . '/modules/admin/nav.php';
    require_once CORE_PATH . '/modules/admin/media-usage.php';

    // content
    require_once CORE_PATH . '/modules/content/content.php';
    require_once CORE_PATH . '/modules/content/taxonomy.php';
    require_once CORE_PATH . '/modules/content/trash.php';
    require_once CORE_PATH . '/modules/content/components.php';
    require_once CORE_PATH . '/modules/content/meta.php';
    require_once CORE_PATH . '/modules/content/checklist.php';
    require_once CORE_PATH . '/modules/content/versions.php';
    require_once CORE_PATH . '/modules/content/publishing.php';
    require_once CORE_PATH . '/modules/content/search.php';
    require_once CORE_PATH . '/modules/content/redirects.php';
    require_once CORE_PATH . '/modules/content/menus.php';

    // render
    require_once CORE_PATH . '/modules/render/images.php';
    require_once CORE_PATH . '/modules/render/theme.php';
    require_once CORE_PATH . '/modules/render/icons.php';

    // seo
    require_once CORE_PATH . '/modules/seo/seo.php';
    require_once CORE_PATH . '/modules/seo/manifest.php';
    require_once CORE_PATH . '/modules/seo/sitemap.php';
    require_once CORE_PATH . '/modules/seo/robots.php';

    // forms
    require_once CORE_PATH . '/modules/forms/submissions.php';

    // operations
    require_once CORE_PATH . '/modules/operations/export.php';
    require_once CORE_PATH . '/modules/operations/backup.php';
    require_once CORE_PATH . '/modules/operations/content-package.php';
    require_once CORE_PATH . '/modules/operations/health.php';

    $GLOBALS['cms_boot_loaded'] = true;
}

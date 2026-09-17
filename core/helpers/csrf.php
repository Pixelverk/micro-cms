<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CSRF Protection
|--------------------------------------------------------------------------
|
| Two flavours of token:
|
| 1. Session tokens (csrf_*) — for admin forms. One token per session,
|    compared with hash_equals(). These never appear in cached HTML.
|
| 2. Signed, time-boxed tokens (form_token_*) — for public forms that are
|    rendered inside cached pages (contact, newsletter). No session state,
|    so a cached page stays valid for anonymous visitors.
|
*/

/**
 * The session CSRF token, created on first use.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Hidden input markup for admin forms.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

/**
 * Compare a submitted token with the session token.
 */
function csrf_check(?string $token = null): bool
{
    $token = $token ?? ($_POST['_token'] ?? null);

    if (!is_string($token) || $token === '') {
        return false;
    }

    $expected = $_SESSION['csrf_token'] ?? null;

    if (!is_string($expected) || $expected === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

/**
 * Abort the request when a submitted token is missing or wrong.
 * Sends JSON for fetch() callers, a toast + redirect for normal forms.
 */
function csrf_assert(): void
{
    if (csrf_check()) {
        return;
    }

    log_activity_safe('security.csrf_failed', [
        'path' => $_SERVER['REQUEST_URI'] ?? '',
        'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $wantsJson = !empty($_POST['_json'])
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || (($_SERVER['HTTP_ACCEPT'] ?? '') !== '' && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json'));

    if ($wantsJson) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid or expired security token. Reload the page and try again.']);
        exit;
    }

    if (function_exists('redirect_with_toast')) {
        redirect_with_toast('dashboard', 'error', 'Invalid or expired security token. Please try again.');
    }

    http_response_code(419);
    exit('Invalid or expired security token.');
}

/*
|--------------------------------------------------------------------------
| Cache-safe signed tokens for public forms
|--------------------------------------------------------------------------
*/

function form_token_secret(): string
{
    static $secret = null;

    if (is_string($secret)) {
        return $secret;
    }

    $configured = config('security.form_secret');

    if (is_string($configured) && strlen($configured) >= 16) {
        return $secret = $configured;
    }

    // Stable per-install fallback so a missing config value still works.
    return $secret = hash('sha256', 'micro-cms|' . (defined('CMS_PATH') ? CMS_PATH : __DIR__));
}

function form_token_bucket(int $ttl = 7200): int
{
    return intdiv(time(), max(60, $ttl));
}

/**
 * Build a signed token for a form type. Cached pages stay valid because the
 * token depends only on the form type and the current time bucket.
 */
function form_token(string $formType, int $ttl = 7200): string
{
    $bucket = form_token_bucket($ttl);
    $mac    = hash_hmac('sha256', $formType . '|' . $bucket, form_token_secret());

    return $bucket . '.' . substr($mac, 0, 32);
}

/**
 * Accept the current or the previous bucket, so tokens survive a page being
 * cached for just under one full TTL.
 */
function form_token_check(string $formType, ?string $token, int $ttl = 7200): bool
{
    if (!is_string($token) || !str_contains($token, '.')) {
        return false;
    }

    [$bucketPart, $mac] = explode('.', $token, 2);

    if (!ctype_digit($bucketPart) || strlen($mac) !== 32) {
        return false;
    }

    $bucket = (int) $bucketPart;

    foreach ([$bucket, $bucket - 1] as $candidate) {
        $expected = substr(hash_hmac('sha256', $formType . '|' . $candidate, form_token_secret()), 0, 32);

        if (hash_equals($expected, $mac)) {
            return true;
        }
    }

    return false;
}

function form_token_field(string $formType, int $ttl = 7200): string
{
    return '<input type="hidden" name="_form_token" value="' . e(form_token($formType, $ttl)) . '">';
}

/*
|--------------------------------------------------------------------------
| Activity log bridge
|--------------------------------------------------------------------------
|
| Phase 8 introduces core/helpers/activity.php. Until then (and when the
| table is missing) this is a no-op, so CSRF failures are never fatal.
|
*/
function log_activity_safe(string $action, array $meta = []): void
{
    if (!function_exists('log_activity')) {
        $file = CORE_PATH . '/helpers/activity.php';

        if (is_file($file) && function_exists('db')) {
            require_once $file;
        }
    }

    if (function_exists('log_activity')) {
        try {
            log_activity($action, null, null, '', $meta);
        } catch (Throwable $exception) {
            // Logging must never break a request.
        }
    }
}

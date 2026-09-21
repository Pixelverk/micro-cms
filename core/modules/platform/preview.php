<?php
declare(strict_types=1);



/*
|--------------------------------------------------------------------------
| Visibility & preview
|--------------------------------------------------------------------------
|
| Every read path that serves a page must agree on who may see unpublished
| content. These helpers are the single source of that rule.
|
| Preview has two layers:
|
|   1. can_preview_content()  — is this person allowed to preview at all?
|   2. is_preview_request()   — did they actually ask for it, with a token?
|
| Only (2) relaxes the query filters and disables caching. Merely being signed
| in must never change what a URL returns, or editor traffic would leak into
| the public HTML cache.
|
*/

/**
 * May the current visitor see drafts / scheduled / archived content?
 *
 * Requires an authenticated user with the preview capability.
 */
function can_preview_content(): bool
{
    if (!is_logged_in()) {
        return false;
    }

    if (!admin_can('content.preview')) {
        return false;
    }

    return true;
}


/**
 * Cookie name holding the per-browser preview token.
 */
function preview_cookie_name(): string
{
    return 'cms_preview';
}


/**
 * The token that turns a normal URL into a preview URL.
 *
 * It lives in its own cookie rather than the session on purpose: the session
 * id is regenerated on login, which used to leave preview links pointing at a
 * token the server no longer had. A cookie survives that untouched.
 */
function preview_token(): string
{
    static $token = null;

    if (is_string($token) && $token !== '') {
        return $token;
    }

    $cookie = $_COOKIE[preview_cookie_name()] ?? null;

    if (is_string($cookie) && preg_match('/^[a-f0-9]{32}$/', $cookie)) {
        return $token = $cookie;
    }

    return $token = bin2hex(random_bytes(16));
}


/**
 * Issue the preview cookie for this browser (called on login).
 */
function preview_token_issue(?string $token = null): string
{
    $token = $token ?? bin2hex(random_bytes(16));

    $_COOKIE[preview_cookie_name()] = $token;

    if (session_status() !== PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        setcookie(preview_cookie_name(), $token, [
            'expires'  => time() + (30 * 86400),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
    }

    return $token;
}


/**
 * Forget the preview cookie (called on logout).
 */
function preview_token_clear(): void
{
    unset($_COOKIE[preview_cookie_name()]);

    if (session_status() !== PHP_SESSION_NONE) {
        setcookie(preview_cookie_name(), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}


/**
 * Is this request an explicit, token-bearing preview?
 */
function is_preview_request(): bool
{
    if (!can_preview_content()) {
        return false;
    }

    $token = $_GET['preview'] ?? null;

    if (!is_string($token) || $token === '') {
        return false;
    }

    $expected = $_COOKIE[preview_cookie_name()] ?? null;

    if (!is_string($expected) || $expected === '') {
        return false;
    }

    return hash_equals($expected, $token);
}


/**
 * Add (or replace) the preview token on a URL.
 */
function preview_url(string $url): string
{
    $token = preview_token();
    $separator = str_contains($url, '?') ? '&' : '?';

    return $url . $separator . 'preview=' . urlencode($token);
}

<?php

/*
|--------------------------------------------------------------------------
| Little Helpers
|--------------------------------------------------------------------------
*/
require_once CORE_PATH . '/helpers/common.php';
bootstrap_core();

/*
|--------------------------------------------------------------------------
| Core Systems
|--------------------------------------------------------------------------
*/
require_once CORE_PATH . '/auth.php';
require CORE_PATH . '/render.php';
require CORE_PATH . '/router.php';

// timezone
define('SITE_TIMEZONE', 'Europe/Stockholm');

function serveAdmin($request) {

    // Start the session with hardened cookie/session settings
    session_boot();

    // Check session timeout
    session_timeout_check();

    // Apply any pending schema migrations (cheap: a marker file when current).
    migrate_run();

    // A password change (a completed reset, or an admin edit) ends sessions
    // that were opened with the old password.
    session_validate_identity();

    // Every admin POST must carry a valid token. The login form is the one
    // exception: there is no session to protect yet, so it relies on the
    // throttle in core/helpers/throttle.php instead.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !admin_is_login_request()) {
        csrf_assert();
    }

    // Dispatch to admin router
    route_admin_request();

}

/**
 * Is this request the login form (or its own assets)?
 */
function admin_is_login_request(): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

    return rtrim($path, '/') === '/admin/login';
}
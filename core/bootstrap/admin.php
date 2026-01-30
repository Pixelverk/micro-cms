<?php

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
require CORE_PATH . '/helpers/icons.php';

/*
|--------------------------------------------------------------------------
| Core Systems
|--------------------------------------------------------------------------
*/
require CORE_PATH . '/auth.php';
require CORE_PATH . '/db.php';
require CORE_PATH . '/render.php';
require CORE_PATH . '/router.php';

// timezone
define('SITE_TIMEZONE', 'Europe/Stockholm');

function serveAdmin($request) {

    // Start session
    if (session_status() === PHP_SESSION_NONE) session_start();

    // Check session timeout
    session_timeout_check();

    // Dispatch to admin router
    route_admin_request();

}
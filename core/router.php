<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Route Request → Page (front-end)
|--------------------------------------------------------------------------
*/

/**
 * Determine which content to serve based on the URL
 */
function route_request(): array
{
    $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/', '/');
    $slug = $path === '' ? get_setting('homepage_slug') : $path; //empty path should show user-defined homepage

    // Contact post case
    if ($path === 'contact' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require CORE_PATH . '/contact.php';
        exit;
    }

    // Sitemap case
    if ($path === 'sitemap.xml') {
        $file = STORAGE_PATH . '/sitemap.xml';

        if (!is_file($file)) {
            return load_fallback_404();
        }

        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }
    
    // No bad stuff in slug
    if (!preg_match('/^[a-z0-9\-\/]+$/', $slug)) {
        return load_fallback_404();
    }
    
    // standard case
    $item = load_content_by_slug($slug);
    if ($item) return $item;

    // whoops, not found
    return load_fallback_404();
}

function load_fallback_404(): array
{
    // hey, it's a 404
    http_response_code(404);

    // get the nice 404 page in theme
    $page = load_content_by_slug('404');

    // if no nice 404 page exist, make an empty page and return that
    if (!$page) {
        $theme = theme_config();
        $settings = load_settings();

        return [
            'id'         => null,
            'type'       => 'page',
            'slug'       => '404',
            'status'     => '404',
            'title'      => 'Page Not Found',
            'layout'     => $settings['default_layout'] ?? $theme['defaults']['layout'] ?? 'default',
            'components' => [],
            'updated_at' => time(),
        ];
    }

    // return the nice 404 page
    $page['status'] = '404';
    return $page;
}

/*
|--------------------------------------------------------------------------
| Admin Routing
|--------------------------------------------------------------------------
*/
function route_admin_request(): void
{
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/admin';
    $path = rtrim($path, '/');

    // Remove /admin prefix
    $page = preg_replace('#^/admin#', '', $path);
    $page = $page === '' || $page === '/' ? 'dashboard' : ltrim($page, '/');

    // Public admin routes
    if ($page === 'login') {
        require CORE_PATH . '/admin/login.php';
        return;
    }

    // Protected admin routes
    require_login();

    // Whitelist allowed characters
    if (!preg_match('/^[a-z0-9\-]+$/', $page)) {
        redirect_with_toast('dashboard', 'error', 'Wow, that slug has some unsafe characters');
        return;
    }

    $file = CORE_PATH . '/admin/' . $page . '.php';
    if (is_file($file)) {
        require $file;
        return;
    }

    redirect_with_toast('dashboard', 'error', 'That admin page does not exist');
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
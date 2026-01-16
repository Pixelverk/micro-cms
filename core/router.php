<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Route Request → Page (front-end)
|--------------------------------------------------------------------------
| Returns a $page array ready for render_page()
|--------------------------------------------------------------------------
*/
function route_request(): array
{
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    $path = trim($path, '/');

    // Normalize slug
    $slug = $path === '' ? 'home' : $path;

    // Security: only allow safe slugs
    if (!preg_match('/^[a-z0-9\-\/]+$/', $slug)) {
        return load_fallback_404();
    }

    // Try exact page
    $page = load_page_by_slug($slug);
    if ($page) {
        return $page;
    }

    // Fallback to 404 page
    return load_fallback_404();
}

function load_page_by_slug(string $slug): ?array
{
    $file = STORAGE_PATH . "/pages/{$slug}.json";

    if (!is_file($file)) {
        return null;
    }

    return load_page_from_json($file, $slug);
}

function load_fallback_404(): array
{
    http_response_code(404);

    $page = load_page_by_slug('404');

    // Absolute last-resort fallback (should never happen)
    if (!$page) {
        return [
            'id'         => null,
            'type'       => 'page',
            'slug'       => '404',
            'status'     => '404',
            'title'      => 'Page Not Found',
            'layout'     => config('defaults.layout'),
            'components' => [],
            'updated_at' => time(),
        ];
    }

    // Force 404 status even if editor forgot
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
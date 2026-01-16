<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Route Request → Page
|--------------------------------------------------------------------------
| Returns a $page array ready for render_page()
|--------------------------------------------------------------------------
*/
function route_request(): array
{
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    $path = rtrim($path, '/');

    if ($path === '') {
        $path = '/';
    }

    // If you are using JSON files for storage:
    $slug = $path === '/' ? 'home' : ltrim($path, '/');
    $pageFile = STORAGE_PATH . "/pages/{$slug}.json";

    if (file_exists($pageFile)) {
        return load_page_from_json($pageFile, $slug);
    }

    // If you decide to use SQLite later, uncomment:
    /*
    $stmt = db()->prepare("
        SELECT * FROM contents
        WHERE slug = :slug
          AND status = 'published'
        LIMIT 1
    ");
    $stmt->execute(['slug' => $path]);
    $row = $stmt->fetch();
    if ($row) {
        return normalize_page($row);
    }
    */

    return not_found_page();
}

/*
|--------------------------------------------------------------------------
| Normalize Page Row (for DB-backed pages)
|--------------------------------------------------------------------------
*/
function normalize_page(array $row): array
{
    $data = json_decode_safe($row['data']);

    return [
        'id'         => (int) $row['id'],
        'type'       => $row['type'],
        'slug'       => $row['slug'],
        'status'     => $row['status'],
        'title'      => $data['title'] ?? '',
        'layout'     => $data['layout'] ?? config('defaults.layout'),
        'components' => $data['components'] ?? [],
        'updated_at' => (int) $row['updated_at'],
    ];
}

/*
|--------------------------------------------------------------------------
| 404 Page
|--------------------------------------------------------------------------
*/
function not_found_page(): array
{
    return [
        'id'         => null,
        'type'       => 'page',
        'slug'       => '',
        'status'     => '404',
        'title'      => 'Page Not Found',
        'layout'     => config('defaults.layout'),
        'components' => [
            [
                'component' => 'hero',
                'props' => [
                    'title' => '404 – Page Not Found',
                ],
            ],
        ],
        'updated_at' => time(),
    ];
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
        admin_not_found();
        return;
    }

    $file = CORE_PATH . '/admin/' . $page . '.php';
    if (is_file($file)) {
        require $file;
        return;
    }

    admin_not_found();
}

function admin_not_found(): void
{
    http_response_code(404);
    echo "<h1>404 – Admin page not found</h1>";
    echo "<p><a href='/admin/'>Back to Dashboard</a></p>";
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
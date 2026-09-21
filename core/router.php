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
function route_request($path): array
{
    $path = trim($path, '/');
    $settings = load_settings();

    // Front page
    if ($path === '') {
        $homepageId = $settings['homepage_id'] ?? null;
        if ($homepageId) {
            $item = load_content_by_id((int)$homepageId);
            if ($item) {
                $path = $item['slug'] ?? '';
            }
        }

        if (!isset($item) || !$item) {
            return load_fallback_404();
        }
    }

    // Form POST
    if ($path === 'form-submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require CORE_PATH . '/modules/forms/submit.php';
        exit;
    }

    // Fresh signed tokens for forms rendered inside cached pages
    if ($path === 'form-token') {
        require CORE_PATH . '/modules/forms/token.php';
        exit;
    }

    // Sitemap
    if ($path === 'sitemap.xml') {
        $file = STORAGE_PATH . '/sitemap.xml';

        // A fresh install has no sitemap yet; build it once on first request
        // rather than serving a 404 to crawlers for the life of the site.
        if (!is_file($file)) {
            try {
                save_sitemap();
            } catch (Throwable $exception) {
                debug_log('sitemap generation failed: ' . $exception->getMessage());
            }
        }

        if (!is_file($file)) {
            return load_fallback_404();
        }

        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }

    // robots.txt (virtual, so there is no file to deploy or keep writable)
    if ($path === 'robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo robots_txt();
        exit;
    }

    // The web app manifest, virtual for the same reason: it is built from
    // Settings, so there is no file to keep in step with them.
    if ($path === 'site.webmanifest') {
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo seo_manifest_json();
        exit;
    }

    // Search results (query-driven, never cached or indexed)
    if ($path === 'search') {
        return route_search_request();
    }

    // Validate slug
    if (!preg_match('/^[a-z0-9\-\/]+$/i', $path)) {
        return load_fallback_404();
    }

    // ----------------------------
    // Taxonomy archives
    // ----------------------------
    // Every declared taxonomy owns its url_prefix; the first path segment picks
    // which one, and the second is the term slug.
    $taxonomyPrefixes = [];

    foreach (theme_taxonomies() as $taxonomyName => $taxonomyConfig) {
        $taxonomyPrefixes[(string) $taxonomyConfig['url_prefix']] = (string) $taxonomyName;
    }

    $segments = explode('/', $path, 2);

    if (count($segments) === 2 && isset($taxonomyPrefixes[$segments[0]])) {
        return load_taxonomy_archive($taxonomyPrefixes[$segments[0]], $segments[1]);
    }

    // Normal page: load by slug
    $item = load_content_by_slug($path);
    if ($item) {
        return $item;
    }

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
            'components' => [ ['type' => '404', 'props' => [], 'children' => [] ] ],
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

    // Public admin routes. These render their own shell, so they are dispatched
    // before require_login() and the capability check.
    if (in_array($page, ['login', 'forgot-password', 'reset-password'], true)) {
        require CMS_PATH . '/admin/auth/' . $page . '.php';
        return;
    }

    // Protected admin routes
    require_login();

    // Whitelist allowed characters
    if (!preg_match('/^[a-z0-9\/\-]+$/', $page)) {
        redirect_with_toast('dashboard', 'error', 'Wow, that slug has some unsafe characters');
        return;
    }

    // The old category and tag pages are the taxonomy page for that name, so a
    // bookmark keeps working. The whole subtree redirects, not just the list.
    $legacy = explode('/', $page, 2);

    if (in_array($legacy[0], ['category', 'tag'], true)) {
        $_GET['type'] = $_REQUEST['type'] = $legacy[0];
        $page = isset($legacy[1]) ? 'taxonomy/' . $legacy[1] : 'taxonomy';
    }

    // Page-level capability check.
    admin_guard($page);

    $file = CMS_PATH . '/admin/' . $page . '.php';

    if (!is_file($file)) {
        $file = CMS_PATH . '/admin/' . $page . '/index.php';
    }

    if (is_file($file)) {
        require $file;
        return;
    }

    redirect_with_toast('dashboard', 'error', 'That admin page does not exist');
}


/*
|--------------------------------------------------------------------------
| Search routing
|--------------------------------------------------------------------------
*/
function route_search_request(): array
{
    $query = trim((string) ($_GET['q'] ?? ''));

    // Taxonomy filters are nested and only declared names are honoured:
    //   ?taxonomy[category]=news&taxonomy[topic]=design
    $requestedTaxonomies = $_GET['taxonomy'] ?? [];
    $taxonomyFilters = [];

    if (is_array($requestedTaxonomies)) {
        foreach (theme_taxonomies() as $taxonomyName => $taxonomyConfig) {
            $slug = trim((string) ($requestedTaxonomies[$taxonomyName] ?? ''));

            if ($slug !== '') {
                $taxonomyFilters[$taxonomyName] = $slug;
            }
        }
    }

    $filters = [
        'type'     => trim((string) ($_GET['type'] ?? '')),
        'taxonomy' => $taxonomyFilters,
    ];

    $page = max(1, (int) ($_GET['page'] ?? 1));

    $results = search_query_is_valid($query)
        ? search_content($query, $filters, $page)
        : ['items' => [], 'total' => 0, 'query' => $query, 'page' => 1, 'pages' => 1];

    $theme = theme_config();
    $settings = load_settings();

    return [
        'id'         => null,
        'type'       => 'search',
        'slug'       => 'search',
        'path'       => 'search',
        'title'      => $query !== '' ? 'Search: ' . $query : 'Search',
        'status'     => 'published',
        'layout'     => $theme['search_layout'] ?? 'search',
        'header'     => $settings['default_header'] ?? $theme['defaults']['header'] ?? 'site-header',
        'footer'     => $settings['default_footer'] ?? $theme['defaults']['footer'] ?? 'site-footer',
        // Search pages are never indexable.
        'meta'       => ['robots_extra' => search_robots()],
        'components' => [],
        'query'      => $query,
        'filters'    => $filters,
        'results'    => $results,
        'no_cache'   => true,
        'updated_at' => time(),
    ];
}

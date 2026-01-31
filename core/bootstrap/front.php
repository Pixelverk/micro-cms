<?php

function checkCache($request, $config) {
    $key = trim($request, '/') ?: 'home';
    $cacheFile = STORAGE_PATH . '/cache/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.html';

    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && file_exists($cacheFile)
        && (time() - filemtime($cacheFile) < $config['cache_lifetime'])
        
    ) {
        return $cacheFile; // return filename
    }
    return false; // no cache
}

function serveCached($file, $config){

    //helpers
    require CORE_PATH . '/helpers/cache.php';
    require CORE_PATH . '/helpers/settings.php';
    require CORE_PATH . '/helpers/sitemap.php';

    //core systems
    require CORE_PATH . '/db.php';

    // Start session
    if (session_status() === PHP_SESSION_NONE) session_start();

    // check for scheduled content items after request is done
    register_shutdown_function('publishing_check');

    // Serve cached page if valid
    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && file_exists($file)
        && (time() - filemtime($file) < $config['cache_lifetime'])
    ) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=' . $config['cache_lifetime']);
        header('X-Cache: HIT');
        echo file_get_contents($file);
    }
}

function serveFresh($request){

    // helpers
    require CORE_PATH . '/helpers/common.php';
    require CORE_PATH . '/helpers/cache.php';
    require CORE_PATH . '/helpers/content.php';
    require CORE_PATH . '/helpers/settings.php';
    require CORE_PATH . '/helpers/menus.php';
    require CORE_PATH . '/helpers/sitemap.php';

    // Core Systems
    require CORE_PATH . '/db.php';
    require CORE_PATH . '/render.php';
    require CORE_PATH . '/router.php';

    // Start session
    if (session_status() === PHP_SESSION_NONE) session_start();

    // check for scheduled content items after request is done
    register_shutdown_function('publishing_check');

    // Resolve page and render
    $page = route_request($request);
    $response = render_page($page);

    http_response_code($response['status'] ?? 200);
    foreach ($response['headers'] ?? [] as $header) header($header);
    echo $response['body'];

    // Cache successful GET responses, but skip drafts, admin visits and archive pages
    $isAdminVisit = !empty($_SESSION['user_id']);
    $isArchivePage = isset($page['taxonomy']);

    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && ($response['status'] ?? 200) === 200
        && ($page['status'] ?? '') !== 'draft'
        && !$isAdminVisit
        && !$isArchivePage
    ) {
        $key = trim($request, '/') ?: 'home';
        $cacheFile = STORAGE_PATH . '/cache/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.html';

        $temp = $cacheFile . '.tmp';
        file_put_contents($temp, $response['body']);
        rename($temp, $cacheFile);
    }

}

// Runs after the page has been served and checks for any cheduled content items that need to be published
function publishing_check() {
    $pdo = db();
    $now = time();

    // Find all scheduled content that should be published
    $stmt = $pdo->prepare("
        SELECT id
        FROM content
        WHERE status = 'scheduled'
          AND scheduled_at IS NOT NULL
          AND scheduled_at <= :now
    ");
    $stmt->execute(['now' => $now]);
    $items = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($items)) {
        return;
    }

    // Publish each item
    $update = $pdo->prepare("
        UPDATE content
        SET status = 'published',
            published_at = :now,
            scheduled_at = NULL,
            updated_at = :now
        WHERE id = :id
    ");

    foreach ($items as $id) {
        $update->execute([
            'id'  => $id,
            'now' => $now,
        ]);

        // Optional: invalidate cache / update sitemap for this item
        $stmt = $pdo->prepare("SELECT slug, type FROM content WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $slug = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($slug) {
            invalidate_cache($slug['slug'], $slug['type']);
        }
    }
    save_sitemap();
};
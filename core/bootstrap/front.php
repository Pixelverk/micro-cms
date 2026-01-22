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
    require CORE_PATH . '/helpers/settings.php';
    require CORE_PATH . '/helpers/menus.php';

    // Core Systems
    require CORE_PATH . '/db.php';
    require CORE_PATH . '/render.php';
    require CORE_PATH . '/router.php';

    // Resolve page and render
    $page = route_request($request);
    $response = render_page($page);

    http_response_code($response['status'] ?? 200);
    foreach ($response['headers'] ?? [] as $header) header($header);
    echo $response['body'];

    // Cache successful GET responses
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($response['status'] ?? 200) === 200) {
        $key = trim($request, '/') ?: 'home';
        $cacheFile = STORAGE_PATH . '/cache/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.html';

        $temp = $cacheFile . '.tmp';
        file_put_contents($temp, $response['body']);
        rename($temp, $cacheFile);
    }

}

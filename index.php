<?php
declare(strict_types=1);

// ====================================================
// JSON-based Front Controller for PHP Component CMS
// ====================================================

// Load Config
$config = require __DIR__ . '/config.php';
$pagesDir = $config['paths']['pages'];
$baseUrl  = rtrim($config['url'], '/');

// ---------------------------
// 1. Parse the requested URL
// ---------------------------
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip baseUrl from path (subdirectory install support)
if ($baseUrl !== '' && ($path === $baseUrl || strpos($path, $baseUrl . '/') === 0)) {
    $path = substr($path, strlen($baseUrl));
}

// Ensure path starts with slash
if ($path === '') {
    $path = '/';
}

// Redirect to trailing slash if missing
if ($path !== '/' && substr($path, -1) !== '/') {
    header('Location: ' . url(trim($path, '/')), true, 301);
    exit;
}

// Normalize slug
$slug = trim($path, '/');
$slug = $slug === '' ? 'home' : $slug;

// ---------------------------
// 2. Locate page JSON file
// ---------------------------
$pageFile = $pagesDir . '/' . $slug . '.json';

// ---------------------------
// 3. Serve page if JSON exists
// ---------------------------
if (file_exists($pageFile)) {
    serve($pageFile, $slug);
}

// ---------------------------
// 4. Serve 404 if page does not exist
// ---------------------------
$notFoundJson = $pagesDir . '/404.json';
if (file_exists($notFoundJson)) {
    serve($notFoundJson, $slug);
}

// Fallback 404
header('HTTP/1.0 404 Not Found');
echo "<h1>404 Not Found</h1>";
exit;


// ====================================================
// Page Rendering
// ====================================================

function serve(string $pageFile, string $slug): void
{
    $json = file_get_contents($pageFile);
    $pageData = json_decode($json, true);

    if (!is_array($pageData)) {
        header('HTTP/1.0 500 Internal Server Error');
        echo "<h1>500 Internal Server Error</h1>";
        exit;
    }

    if (!empty($pageData['is404'])) {
        header('HTTP/1.0 404 Not Found');
    }

    $collectedJs = [];
    $collectedCss = [];

    ob_start();
    renderComponents($pageData['layout']['header'] ?? [], $collectedJs, $collectedCss);
    $headerHtml = ob_get_clean();

    ob_start();
    renderComponents($pageData['components'] ?? [], $collectedJs, $collectedCss);
    $componentHtml = ob_get_clean();

    ob_start();
    renderComponents($pageData['layout']['footer'] ?? [], $collectedJs, $collectedCss);
    $footerHtml = ob_get_clean();

    header('Content-Type: text/html; charset=utf-8');

    echo "<!DOCTYPE html><html lang='en'><head>";
    echo "<meta charset='UTF-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
    echo "<title>" . e($pageData['title'] ?? $slug) . "</title>";

    if (!empty($pageData['meta']['description'])) {
        echo "<meta name='description' content='" . e($pageData['meta']['description']) . "'>";
    }

    // Assets
    echo "<link rel='stylesheet' href='" . url('_assets/style.css') . "'>";
    echo "<script src='" . url('_assets/main.js') . "' defer></script>";
    echo "<script src='" . url('_assets/vendor/instant-page.min.js') . "' defer></script>";

    // Component CSS
    if ($collectedCss) {
        echo "<style>";
        foreach ($collectedCss as $c) {
            echo $c['content'];
        }
        echo "</style>";
    }

    // Component JS
    if ($collectedJs) {
        echo "<script>document.addEventListener('DOMContentLoaded',function(){";
        foreach ($collectedJs as $j) {
            echo $j['content'];
        }
        echo "});</script>";
    }

    echo "</head><body>";
    echo $headerHtml;
    echo "<main>$componentHtml</main>";
    echo $footerHtml;
    echo "</body></html>";
    exit;
}

// Render single component
function renderComponent(string $name, array $props = [], array &$collectedJs = [], array &$collectedCss = []): void {
    $componentDir = __DIR__ . "/_components/{$name}";

    // Load component PHP
    $componentFile = "{$componentDir}/body.php";
    if (!file_exists($componentFile)) {
        throw new RuntimeException("Component '{$name}' not found at {$componentFile}");
    }

    $component = require $componentFile;

    // --------------------------------------------
    // CSS: collect content (deduplicated by file path)
    // --------------------------------------------
    $cssFile = "{$componentDir}/style.css";
    if (file_exists($cssFile) && !in_array($cssFile, array_column($collectedCss, 'file'), true)) {
        $collectedCss[] = [
            'file' => $cssFile,
            'content' => "/* CSS from {$name} */\n" . file_get_contents($cssFile)
        ];
    }

    // --------------------------------------------
    // JS: collect content (deduplicated by file path)
    // --------------------------------------------
    $jsFile = "{$componentDir}/script.js";
    if (file_exists($jsFile) && !in_array($jsFile, array_column($collectedJs, 'file'), true)) {
        $collectedJs[] = [
            'file' => $jsFile,
            'content' => "// JS from {$name}\n" . file_get_contents($jsFile)
        ];
    }

    // --------------------------------------------
    // Render HTML
    // --------------------------------------------
    if (is_callable($component['render'])) {
        echo $component['render']($props, $collectedJs, $collectedCss);
    } else {
        throw new RuntimeException("Component '{$name}' has no render function.");
    }
}

// render all components
function renderComponents(array $components, array &$collectedJs = [], array &$collectedCss = []): void {
    foreach ($components as $comp) {
        $type = $comp['type'] ?? null;
        $props = $comp['props'] ?? [];
        $children = $comp['children'] ?? [];

        if (!$type) continue;

        if (!empty($children)) {
            $props['children'] = $children;
        }

        renderComponent($type, $props, $collectedJs, $collectedCss);
    }
}

// HTML escape helper
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// URL helper
function url(string $path = ''): string
{
    global $config; // reuse already loaded config
    $baseUrl = rtrim($config['url'], '/');

    $path = ltrim($path, '/');

    if ($path === '') {
        return $baseUrl . '/';
    }

    if (pathinfo($path, PATHINFO_EXTENSION)) {
        return $baseUrl . '/' . $path;
    }

    return $baseUrl . '/' . rtrim($path, '/') . '/';
}
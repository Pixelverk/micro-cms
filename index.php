<?php
declare(strict_types=1);

// ====================================================
// JSON-based Front Controller for Web Component CMS
// ====================================================

// Define the folder where page JSON files are stored
$pagesDir = __DIR__ . '/_pages';

// ---------------------------
// 1. Parse the requested URL
// ---------------------------
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Redirect to trailing slash if missing
if ($path !== '/' && substr($path, -1) !== '/') {
    header("Location: $path/", true, 301);
    exit;
}

// Normalize slug
$slug = trim($path, '/');
if ($slug === '') $slug = 'home';

// Escape helper
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// ---------------------------
// 2. Locate page JSON file
// ---------------------------
$pageFile = $pagesDir . '/' . $slug . '.json';

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

// ---------------------------
// 4. Serve page if JSON exists
// ---------------------------
if (file_exists($pageFile)) {
    serve($pageFile);
}

// ---------------------------
// 5. Serve 404 if JSON does not exist
// ---------------------------
$notFoundJson = $pagesDir . '/404.json';
if (file_exists($notFoundJson)) {
    serve($notFoundJson);
} else {
    // Default 404 fallback
    header("HTTP/1.0 404 Not Found");
    echo "<h1>404 Not Found</h1>";
    echo "<p>The page '$slug' does not exist.</p>";
    exit;
}

// Serve the things
function serve($pageFile){
    $json = file_get_contents($pageFile);
    $pageData = json_decode($json, true);

    if ($pageData === null) {
        header("HTTP/1.0 500 Internal Server Error");
        echo "<h1>500 Internal Server Error</h1>";
        echo "<p>Invalid JSON in '$slug.json'</p>";
        exit;
    }

    if (isset($pageData['is404'])) {
        header('HTTP/1.0 404 Not Found');
    }

    // Pre-render header/components and collect styles + scripts
    $collectedJs = [];
    $collectedCss = [];

    // Render header
    ob_start();
    renderComponents($pageData['layout']['header'] ?? [], $collectedJs, $collectedCss);
    $headerHtml = ob_get_clean();

    // Render main components
    ob_start();
    renderComponents($pageData['components'] ?? [], $collectedJs, $collectedCss);
    $componentHtml = ob_get_clean();

    // Render footer
    ob_start();
    renderComponents($pageData['layout']['footer'] ?? [], $collectedJs, $collectedCss);
    $footerHtml = ob_get_clean();

    // ---------------------------
    // 6. Output HTML
    // ---------------------------
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>\n<html lang='en'>\n<head>\n";
    echo "<meta charset='UTF-8'>\n";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
    echo "<title>" . e($pageData['title'] ?? $slug) . "</title>\n";

    if (!empty($pageData['meta']['description'])) {
        echo "  <meta name='description' content=\"" . e($pageData['meta']['description']) . "\">\n";
    }

    // Global JS
    echo "<script src='/_assets/main.js' defer></script>\n";
    echo "<script src='/_vendor/alpine.min.js' defer></script>\n";
    echo "<script src='/_vendor/instant-page.min.js' defer></script>\n";
    
    // Component JS
    if (!empty($collectedJs)) {
        echo "<script>document.addEventListener('DOMContentLoaded', function() {\n";
        foreach ($collectedJs as $j) {
            echo $j['content'] . "\n";
        }
        echo "});\n</script>\n";
    }

    // Global CSS
    echo "<link rel='stylesheet' href='/_assets/style.css'>\n";

    // Component CSS
    if (!empty($collectedCss)) {
        echo "<style>\n";
        foreach ($collectedCss as $c) {
            echo $c['content'] . "\n";
        }
        echo "</style>\n";
    };

    echo "</head>\n<body>\n";

    // Output pre-rendered HTML
    echo $headerHtml;
    echo "<main>\n$componentHtml\n</main>\n";
    echo $footerHtml;

    // Close document
    echo "</body>\n</html>";
    exit;
}
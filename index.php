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

// ---------------------------
// 2. Locate page JSON file
// ---------------------------
$pageFile = $pagesDir . '/' . $slug . '.json';

// ---------------------------
// 3. Function to render components recursively
// ---------------------------
function renderComponents(array $components, array &$usedScripts = []): string {
    $html = '';

    foreach ($components as $comp) {
        $type = $comp['type'] ?? '';
        $props = $comp['props'] ?? [];
        $children = $comp['children'] ?? [];

        if (!$type) continue;

        // Build attribute string
        $attrStr = '';
        foreach ($props as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = htmlspecialchars(json_encode($value), ENT_QUOTES);
            } else {
                $value = htmlspecialchars((string)$value, ENT_QUOTES);
            }
            $attrStr .= " $key=\"$value\"";
        }

        // Add component tag
        if (!empty($children)) {
            $html .= "<$type$attrStr>\n";
            $html .= renderComponents($children, $usedScripts);
            $html .= "</$type>\n";
        } else {
            $html .= "<$type$attrStr></$type>\n";
        }

        // Track scripts to include
        if (!in_array($type, $usedScripts, true)) {
            $usedScripts[] = $type;
        }
    }

    return $html;
}

// ---------------------------
// 4. Serve page if JSON exists
// ---------------------------
if (file_exists($pageFile)) {
    $json = file_get_contents($pageFile);
    $pageData = json_decode($json, true);

    if ($pageData === null) {
        header("HTTP/1.0 500 Internal Server Error");
        echo "<h1>500 Internal Server Error</h1>";
        echo "<p>Invalid JSON in '$slug.json'</p>";
        exit;
    }

    $usedScripts = [];

    // Pre-render header/components to collect used scripts
    $headerHtml = renderComponents($pageData['layout']['header'] ?? [], $usedScripts);
    $componentHtml = renderComponents($pageData['components'] ?? [], $usedScripts);
    $footerHtml = renderComponents($pageData['layout']['footer'] ?? [], $usedScripts);

    // ---------------------------
    // 5. Output HTML
    // ---------------------------
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>\n<html lang='en'>\n<head>\n";
    echo "  <meta charset='UTF-8'>\n";
    echo "  <meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
    echo "  <title>" . htmlspecialchars($pageData['title'] ?? $slug) . "</title>\n";

    if (!empty($pageData['meta']['description'])) {
        echo "  <meta name='description' content=\"" . htmlspecialchars($pageData['meta']['description']) . "\">\n";
    }

    // Global CSS
    echo "  <link rel='stylesheet' href='/_assets/style.css'>\n";

    // Global JS
    echo "  <script src='/_assets/main.js' defer></script>\n";
    echo "  <script src='/_vendor/alpine.min.js' defer></script>\n";
    echo "  <script src='/_vendor/instant-page.min.js' defer></script>\n";

    // ---------------------------
    // Include all component scripts **before body HTML**
    // ---------------------------
    foreach ($usedScripts as $script) {
        echo "  <script type='module' src='/_components/$script.js' defer></script>\n";
    }

    echo "</head>\n<body>\n";

    // Output pre-rendered HTML
    echo $headerHtml;
    echo "<main>\n$componentHtml\n</main>\n";
    echo $footerHtml;

    echo "</body>\n</html>";
    exit;
}

// ---------------------------
// 6. Handle 404 pages
// ---------------------------
$notFoundJson = $pagesDir . '/404.json';
if (file_exists($notFoundJson)) {
    $json = file_get_contents($notFoundJson);
    $pageData = json_decode($json, true);

    header('HTTP/1.0 404 Not Found');
    echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head><body>";
    echo renderComponents($pageData['components'] ?? []);
    echo "</body></html>";
    exit;
} else {
    // Default 404 fallback
    header("HTTP/1.0 404 Not Found");
    echo "<h1>404 Not Found</h1>";
    echo "<p>The page '$slug' does not exist.</p>";
    exit;
}
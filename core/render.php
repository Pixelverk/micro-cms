<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Render Full Page
|--------------------------------------------------------------------------
*/
function render_page(array $page): array
{
    //$layoutName = config('defaults.layout'); // string
    $layoutName = 'default';

    $collectedCss = [];
    $collectedJs  = [];

    ob_start();
    render_layout($layoutName, $page, $collectedJs, $collectedCss);
    $bodyContent = ob_get_clean();

    $siteTitle = e(get_setting('site_title', 'My Site'));
    $pageTitle = e($page['title'] ?? 'Untitled');
    $metaDesc  = e($page['meta']['description'] ?? '');

    $head = "<meta charset='UTF-8'>\n";
    $head .= "<meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
    $head .= "<title>{$pageTitle} - {$siteTitle}</title>\n";

    if ($metaDesc !== '') {
        $head .= "<meta name='description' content='{$metaDesc}'>\n";
    }

    // Core assets — use asset() helper
    $head .= "<link rel='stylesheet' href='" . asset('style.css') . "'>\n";
    $head .= "<script src='" . asset('main.js') . "' defer></script>\n";
    $head .= "<script src='" . asset('vendor/instant-page.min.js') . "' defer></script>\n";

    // Component CSS
    if ($collectedCss) {
        $head .= "<style>\n";
        foreach ($collectedCss as $c) {
            $head .= $c['content'] . "\n";
        }
        $head .= "</style>\n";
    }

    // Component JS
    if ($collectedJs) {
        $head .= "<script>document.addEventListener('DOMContentLoaded', function(){\n";
        foreach ($collectedJs as $j) {
            $head .= $j['content'] . "\n";
        }
        $head .= "});</script>\n";
    }

    $html = "<!DOCTYPE html>\n<html lang='en'>\n<head>\n{$head}</head>\n<body>\n";
    $html .= $bodyContent;
    $html .= "\n</body>\n</html>";

    return [
        'status'  => ($page['status'] ?? '') === '404' ? 404 : 200,
        'headers' => ['Content-Type: text/html; charset=utf-8'],
        'body'    => $html,
    ];
}

/*
|--------------------------------------------------------------------------
| Render Layout
|--------------------------------------------------------------------------
| Expects $collectedJs and $collectedCss arrays passed by reference
|--------------------------------------------------------------------------
*/
function render_layout(string $layout, array $page, array &$collectedJs = [], array &$collectedCss = []): void
{
    $layoutFile = theme("layout/{$layout}.php");

    if (!file_exists($layoutFile)) {
        throw new RuntimeException("Layout not found: {$layout}");
    }

    // Make $page, $collectedJs, $collectedCss available to layout
    require $layoutFile;
}

/*
|--------------------------------------------------------------------------
| Render a List of Components
|--------------------------------------------------------------------------
*/
function render_components(array $components, array &$collectedJs = [], array &$collectedCss = []): void
{
    foreach ($components as $comp) {
        $name = $comp['component'] ?? $comp['type'] ?? null;
        if (!$name) continue;

        $props = $comp['props'] ?? [];

        // Merge children into props
        if (!empty($comp['children'])) {
            $props['children'] = $comp['children'];
        }

        component($name, $props, $collectedJs, $collectedCss);
    }
}

/*
|--------------------------------------------------------------------------
| Render a Single Component
|--------------------------------------------------------------------------
*/
function component(string $name, array $props = [], array &$collectedJs = [], array &$collectedCss = []): void
{
    $componentDir = theme("components/{$name}");
    $componentFile = "{$componentDir}/body.php";

    if (!file_exists($componentFile)) {
        throw new RuntimeException("Component '{$name}' not found at {$componentFile}");
    }

    $component = require $componentFile;

    // -----------------------------
    // Collect CSS
    // -----------------------------
    $cssFile = "{$componentDir}/style.css";
    if (file_exists($cssFile) && !in_array($cssFile, array_column($collectedCss, 'file'), true)) {
        $collectedCss[] = [
            'file' => $cssFile,
            'content' => "\n/* CSS from {$name} */\n" . file_get_contents($cssFile),
        ];
    }

    // -----------------------------
    // Collect JS
    // -----------------------------
    $jsFile = "{$componentDir}/script.js";
    if (file_exists($jsFile) && !in_array($jsFile, array_column($collectedJs, 'file'), true)) {
        $collectedJs[] = [
            'file' => $jsFile,
            'content' => "\n// JS from {$name}\n" . file_get_contents($jsFile),
        ];
    }

    // -----------------------------
    // Render HTML
    // -----------------------------
    if (!is_callable($component['render'])) {
        throw new RuntimeException("Component '{$name}' has no render function.");
    }

    // Execute render function
    echo $component['render']($props, $collectedJs, $collectedCss);
}
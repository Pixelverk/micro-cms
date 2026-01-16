<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Render Full Page
|--------------------------------------------------------------------------
*/
function render_page(array $page): array
{
    $layoutName = 'default';

    $collectedCss = [];
    $collectedJs  = [];

    ob_start();
    render_layout($layoutName, $page, $collectedJs, $collectedCss);
    $bodyContent = ob_get_clean();

    $theme = theme_config();

    $siteTitle = e(get_setting('site_title', 'My Site'));
    $pageTitle = e($page['title'] ?? 'Untitled');
    $metaDesc  = e($page['meta']['description'] ?? '');

    $head = '';

    // Charset & viewport
    $meta = $theme['meta'] ?? [];
    $head .= "<meta charset='" . e($meta['charset'] ?? 'UTF-8') . "'>\n";
    $head .= "<meta name='viewport' content='" . e($meta['viewport'] ?? 'width=device-width, initial-scale=1.0') . "'>\n";

    // Title
    $head .= "<title>{$pageTitle} - {$siteTitle}</title>\n";

    // Meta description
    if ($metaDesc !== '') {
        $head .= "<meta name='description' content='{$metaDesc}'>\n";
    }

    // Icons
    if (!empty($theme['icons']['favicon'])) {
        $head .= "<link rel='icon' href='" . asset($theme['icons']['favicon']) . "'>\n";
    }

    // Styles
    foreach ($theme['styles'] ?? [] as $style) {
        $head .= "<link rel='stylesheet' href='" . asset($style) . "'>\n";
    }

    // Scripts
    foreach ($theme['scripts'] ?? [] as $script) {
        $src   = asset($script['src']);
        $defer = !empty($script['defer']) ? ' defer' : '';
        $head .= "<script src='{$src}'{$defer}></script>\n";
    }

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
        $head .= "<script>\n";
        $head .= "document.addEventListener('DOMContentLoaded', function() {\n";
        foreach ($collectedJs as $j) {
            $head .= $j['content'] . "\n";
        }
        $head .= "});\n</script>\n";
    }

    $html = "<!DOCTYPE html>\n<html lang='" . e(get_setting('site_language')) . "'>\n<head>\n{$head}</head>\n<body>\n";
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
    $layoutFile = theme("layouts/{$layout}.php");

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

function component(string $name, array $props = [], array &$collectedJs = [], array &$collectedCss = []): void {
    
    // get the file
    $componentFile = theme("components/{$name}.php");

    if (!file_exists($componentFile)) {
        throw new RuntimeException("Component '{$name}' not found at {$componentFile}");
    }

    $component = require $componentFile;

    // -----------------------------
    // Add CSS once
    // -----------------------------
    if (!empty($component['css']) && !in_array($name, array_column($collectedCss, 'file'), true)) {
        $collectedCss[] = [
            'file'    => $name,
            'content' => "/* CSS from component: {$name} */\n" . $component['css'],
        ];
    }

    // -----------------------------
    // Add JS once
    // -----------------------------
    if (!empty($component['js']) && !in_array($name, array_column($collectedJs, 'file'), true)) {
        $collectedJs[] = [
            'file'    => $name,
            'content' => "/* JS from component: {$name} */\n" . $component['js'],
        ];
    }

    // -----------------------------
    // Render HTML
    // -----------------------------
    if (!is_callable($component['render'])) {
        throw new RuntimeException("Component '{$name}' has no render function.");
    }

    $component['render']($props, $collectedJs, $collectedCss);
}
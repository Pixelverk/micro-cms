<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Render Full Page
|--------------------------------------------------------------------------
*/
function render_page(array $page): array
{
    $theme = theme_config();

    // Use page layout string, or fallback to site default
    $layoutName = $page['layout'] ?? get_setting('default_layout', null) ?? $theme['defaults']['layout'];

    $collectedCss = [];
    $collectedJs  = [];

    ob_start();
    render_layout($layoutName, $page, $collectedJs, $collectedCss);
    $bodyContent = ob_get_clean();

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
    $html .= "</body>\n</html>";

    // Minify based on environment, defaults to 'production'
    if (config('env', 'production') === 'production') {
        $html = minify_html($html);
    }

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
function render_layout(string $layout, array $page, array &$collectedJs = [], array &$collectedCss = [])
{
    $layoutFile = theme("layouts/{$layout}.php");

    $theme = theme_config();
    $settings = load_settings();

    if (!file_exists($layoutFile)) {
        throw new RuntimeException("Layout '{$layout}' not found.");
    }

    $headerComponent = $page['header'] ?? $settings['default_header'] ?? $theme['defaults']['header'];
    $footerComponent = $page['footer'] ?? $settings['default_footer'] ?? $theme['defaults']['footer'];

    // Include the layout
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
    
    // set the file name
    $componentFile = theme("components/{$name}.php");

    // handle missing file
    if (!file_exists($componentFile)) {
        trigger_error("Component '{$name}' not found at {$componentFile}", E_USER_WARNING);
        echo "<div style='width:fit-content; margin: 3rem auto;'> {$name} - component not found </div>";
        return;
    }

    // attempt to load the file
    $component = require $componentFile;

    // panic if component is not array
    if (!is_array($component)) {
        trigger_error("Component '{$name}' must return an array.", E_USER_WARNING);
        return;
    }

    // Add component CSS to collection
    if (!empty($component['css']) && !in_array($name, array_column($collectedCss, 'file'), true)) {
        $collectedCss[] = [
            'file'    => $name,
            'content' => "/* CSS from component: {$name} */\n" . $component['css'],
        ];
    }

    // Add component JS to collection
    if (!empty($component['js']) && !in_array($name, array_column($collectedJs, 'file'), true)) {
        $collectedJs[] = [
            'file'    => $name,
            'content' => "/* JS from component: {$name} */\n" . $component['js'],
        ];
    }

    // handle missing render
    if (!is_callable($component['render'] ?? null)) {
        trigger_error("Component '{$name}' has no render function.", E_USER_WARNING);
        return;
    }

    // render component html
    $component['render']($props, $collectedJs, $collectedCss);
}
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

    $head = '';

    // Charset & viewport
    $meta = $theme['meta'] ?? [];
    $head .= "<meta charset='" . e($meta['charset'] ?? 'UTF-8') . "'>\n";
    $head .= "<meta name='viewport' content='" . e($meta['viewport'] ?? 'width=device-width, initial-scale=1.0') . "'>\n";

    // Title, description, canonical, robots, Open Graph and Twitter tags.
    // All of it comes from core/helpers/seo.php so defaults stay in one place.
    $head .= seo_head_tags($page);
    $head .= seo_json_ld($page);

    // rel=prev/next for a paged listing. The listing records itself while the
    // body renders (pagination_result), which is the only way a component can
    // reach the head: the page array is passed to components by value. The
    // canonical above already carries the current page number.
    $listing = $page['pagination'] ?? ($GLOBALS['cms_pagination'] ?? null);

    if (is_array($listing) && (int) ($listing['pages'] ?? 1) > 1) {
        $pagerBase = (string) ($listing['url'] ?? '');
        if ($pagerBase === '') {
            $pagerBase = url(trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'));
        }

        $head .= pagination_link_tags($listing, $pagerBase);
    }

    // Icons
    $favicon = site_favicon_url();

    if ($favicon !== '') {
        $head .= "<link rel='icon' href='" . e($favicon) . "'>\n";
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

    // Site CSS from Settings. Raw, trusted input (settings.manage), placed last
    // so it can override the theme and component styles. minify_html() leaves
    // <style> alone.
    $customCss = trim((string) get_setting('custom_css', ''));

    if ($customCss !== '') {
        $head .= "<style>\n" . $customCss . "\n</style>\n";
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

    $html = "<!DOCTYPE html>\n<html lang='" . e(get_setting('site_language', 'en')) . "'>\n<head>\n{$head}</head>\n<body>\n";
    $html .= render_preview_bar($page);
    $html .= $bodyContent;
    $html .= "</body>\n</html>";

    // Minify based on environment, defaults to 'production'
    if (config('env', 'production') === 'production') {
        $html = minify_html($html);
    }

    // Administrator-supplied code goes in last so the minifier never rewrites
    // it. It is raw, trusted input (settings.manage), not escaped output.
    $html = inject_site_scripts($html);

    return [
        'status'  => ($page['status'] ?? '') === '404' ? 404 : 200,
        'headers' => ['Content-Type: text/html; charset=utf-8'],
        'body'    => $html,
    ];
}

/*
|--------------------------------------------------------------------------
| Inject site header/footer scripts
|--------------------------------------------------------------------------
| Two raw snippets from Settings, placed just before the closing head and
| body tags. Called after minify_html() on purpose: the snippets are code,
| and the HTML minifier has no business touching them.
*/
function inject_site_scripts(string $html): string
{
    $placements = [
        '</head>' => (string) get_setting('header_scripts', ''),
        '</body>' => (string) get_setting('footer_scripts', ''),
    ];

    foreach ($placements as $tag => $code) {
        if (trim($code) === '') {
            continue;
        }

        $position = strpos($html, $tag);

        if ($position !== false) {
            $html = substr($html, 0, $position) . $code . "\n" . substr($html, $position);
        }
    }

    return $html;
}

/*
|--------------------------------------------------------------------------
| Draft Preview Bar
|--------------------------------------------------------------------------
| Only ever rendered for a token-bearing preview request, so it can never
| appear in cached HTML.
|--------------------------------------------------------------------------
*/
function render_preview_bar(array $page): string
{
    if (!is_preview_request()) {
        return '';
    }

    $status   = (string) ($page['status'] ?? 'published');
    $title    = (string) ($page['title'] ?? 'Untitled');
    $contentId = $page['id'] ?? null;

    $statusText = match ($status) {
        'draft'     => 'Draft — not visible to visitors',
        'scheduled' => 'Scheduled — goes live later',
        'archived'  => 'Archived — hidden from visitors',
        '404'       => 'Not found page',
        default     => 'Published',
    };

    $isUnpublished = $status !== 'published';

    $editUrl = $contentId
        ? url('admin/content/edit') . '?id=' . (int) $contentId . '&type=' . urlencode((string) ($page['type'] ?? 'page'))
        : '';

    $exitUrl = $_SERVER['REQUEST_URI'] ?? '/';
    $exitUrl = preg_replace('/([?&])preview=[^&]*&?/', '$1', $exitUrl) ?? $exitUrl;
    $exitUrl = rtrim($exitUrl, '?&');

    ob_start();
    ?>
    <style>
        #cms-preview-bar {
            position: fixed;
            inset: 0 0 auto 0;
            z-index: 2147483000;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 1rem;
            font: 500 0.8125rem/1.4 system-ui, -apple-system, "Segoe UI", sans-serif;
            color: #fff;
            background: <?= $isUnpublished ? '#b45309' : '#1f2937' ?>;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
        }
        #cms-preview-bar .cms-preview-status { display: flex; gap: 0.5rem; align-items: center; }
        #cms-preview-bar .cms-preview-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: <?= $isUnpublished ? '#fbbf24' : '#34d399' ?>;
        }
        #cms-preview-bar .cms-preview-actions { display: flex; gap: 0.5rem; align-items: center; }
        #cms-preview-bar a {
            color: #fff;
            text-decoration: none;
            padding: 0.25rem 0.6rem;
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 4px;
        }
        #cms-preview-bar a:hover { background: rgba(255, 255, 255, 0.15); }
        body { padding-top: 2.75rem; }
    </style>
    <div id="cms-preview-bar" role="status">
        <span class="cms-preview-status">
            <span class="cms-preview-dot" aria-hidden="true"></span>
            <strong>Preview</strong>
            <span><?= e($statusText) ?></span>
            <span aria-hidden="true">·</span>
            <span><?= e($title) ?></span>
        </span>
        <span class="cms-preview-actions">
            <?php if ($editUrl !== ''): ?>
                <a href="<?= e($editUrl) ?>">Edit</a>
            <?php endif; ?>
            <a href="<?= e($exitUrl) ?>">Exit preview</a>
        </span>
    </div>
    <?php
    return (string) ob_get_clean();
}

/*
|--------------------------------------------------------------------------
| Collect inline CSS / JS
|--------------------------------------------------------------------------
| Components and layouts both contribute assets. The key de-duplicates, so a
| layout can safely offer CSS that a component might also provide.
|--------------------------------------------------------------------------
*/
function collect_css(array &$collectedCss, string $key, string $css): void
{
    if ($css === '' || in_array($key, array_column($collectedCss, 'file'), true)) {
        return;
    }

    $collectedCss[] = ['file' => $key, 'content' => $css];
}

function collect_js(array &$collectedJs, string $key, string $js): void
{
    if ($js === '' || in_array($key, array_column($collectedJs, 'file'), true)) {
        return;
    }

    $collectedJs[] = ['file' => $key, 'content' => $js];
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

    // The path is known by the router but not stored on the page; the default
    // layout needs it (canonical URLs, active nav state) so fill it in here.
    if (!isset($page['path'])) {
        $page['path'] = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
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
function render_components(array $components, array $page, array &$collectedJs = [], array &$collectedCss = []): void
{
    foreach ($components as $comp) {
        $name = $comp['type'] ?? null;
        if (!$name) continue;

        $props = $comp['props'] ?? [];

        // Merge children into props
        if (!empty($comp['children'])) {
            $props['children'] = $comp['children'];
        }

        component($name, $props, $page, $collectedJs, $collectedCss);
    }
}

/*
|--------------------------------------------------------------------------
| Fill an empty image prop with its declared placeholder
|--------------------------------------------------------------------------
|
| A schema image field's `default` is the theme's placeholder image: it fills a
| new component in the editor, and it comes back when an editor clears the
| field. Only image fields are filled — a cleared text field stays empty. A
| theme that wants "no image" on an empty value declares no default.
|
*/
function component_image_defaults(array $props, array $schema): array
{
    foreach ($schema as $field => $rules) {
        if (!is_array($rules) || ($rules['type'] ?? '') !== 'image') {
            continue;
        }

        if (trim((string) ($props[$field] ?? '')) !== '') {
            continue;
        }

        $default = (string) ($rules['default'] ?? '');

        if ($default !== '') {
            $props[$field] = $default;
        }
    }

    return $props;
}

/*
|--------------------------------------------------------------------------
| Render a Single Component
|--------------------------------------------------------------------------
*/

function component(string $name, array $props, array $page, array &$collectedJs = [], array &$collectedCss = []): void {
    
 
    // get component path in theme or core
    $componentPath = theme("components/{$name}.php");

    if (!file_exists($componentPath)) {
        $componentPath = CORE_PATH . "/components/{$name}.php";
    }

    // handle missing file
    if (!file_exists($componentPath)) {
        trigger_error("Component '{$name}' not found at {$componentPath}", E_USER_WARNING);
        echo "<div style='width:fit-content; margin: 3rem auto;'> {$name} - component not found </div>";
        return;
    }

    // attempt to load the file
    $component = require $componentPath;

    // panic if component is not array
    if (!is_array($component)) {
        trigger_error("Component '{$name}' must return an array.", E_USER_WARNING);
        return;
    }

    // Add component CSS / JS to the collections (de-duplicated by name)
    if (!empty($component['css'])) {
        collect_css($collectedCss, $name, "/* CSS from component: {$name} */\n" . $component['css']);
    }

    if (!empty($component['js'])) {
        collect_js($collectedJs, $name, "/* JS from component: {$name} */\n" . $component['js']);
    }

    // handle missing render
    if (!is_callable($component['render'] ?? null)) {
        trigger_error("Component '{$name}' has no render function.", E_USER_WARNING);
        return;
    }

    // An image the editor cleared falls back to the theme's placeholder.
    $props = component_image_defaults($props, (array) ($component['schema'] ?? []));

    // render component html
    $component['render']($props, $page, $collectedJs, $collectedCss);
}
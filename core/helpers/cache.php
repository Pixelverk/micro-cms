<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cache Invalidation Helpers
|--------------------------------------------------------------------------
|
| Usage:
| - invalidate_cache();          // Clear all cached pages
| - invalidate_cache('/about'); // Clear one page by path
|
*/

function invalidate_cache(string $path = '', string $type = ''): void
{
    // If path is empty, delete all cache files
    if ($path === '/' || $path === '') {
        $files = glob(STORAGE_PATH . '/cache/*.html');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        return;
    }

    // check for prefix
    $settings = load_settings();
    $prefixes = $settings['content_prefixes'] ?? [];
    $prefix = $prefixes[$type] ?? '';
    $cachePath = $prefix ? "{$prefix}/{$path}" : $path;

    // Sanitize path into cache filename
    $key = trim($cachePath, '/') ?: 'home';
    $cacheFile = STORAGE_PATH . '/cache/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.html';

    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
}

function minify_html(string $html): string {
    // Collapse whitespace everywhere except inside elements where it is
    // significant, so inline scripts and preformatted text survive intact.
    $protected = [];

    $html = preg_replace_callback(
        '#<(script|style|pre|textarea)\b[^>]*>.*?</\1>#is',
        function (array $match) use (&$protected) {
            $key = "\x00MINIFY" . count($protected) . "\x00";
            $protected[$key] = $match[0];

            return $key;
        },
        $html
    ) ?? $html;

    $html = preg_replace('/\s+/', ' ', $html) ?? $html;
    $html = preg_replace('/>\s+</', '><', $html) ?? $html;

    if ($protected) {
        $html = strtr($html, $protected);
    }

    return trim($html);
}
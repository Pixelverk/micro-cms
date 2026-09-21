<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cache Helpers
|--------------------------------------------------------------------------
|
| Usage:
| - cache_file_for('/about');    // Cache-file path for a request path
| - invalidate_cache();          // Clear all cached pages
| - invalidate_cache('/about'); // Clear one page by path
|
*/

/**
 * Path of the cache file for a request path.
 *
 * The single source of the cache-key rule. The reader (core/bootstrap/front.php)
 * and the invalidator below must agree on it, or an edit leaves a stale page
 * behind.
 */
function cache_file_for(string $request): string
{
    $key = trim($request, '/') ?: 'home';

    return STORAGE_PATH . '/cache/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.html';
}

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

    $cacheFile = cache_file_for($cachePath);

    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
}

/**
 * Write rendered HTML to the page cache for a request path.
 *
 * Written to a temporary file and renamed, so a visitor never reads a
 * half-written page. Shared by the front-end cache write and cache warm-up.
 *
 * @return bool false when the cache directory is not writable.
 */
function cache_write(string $request, string $html): bool
{
    $file = cache_file_for($request);
    $temp = $file . '.tmp';

    if (file_put_contents($temp, $html) === false) {
        return false;
    }

    if (!rename($temp, $file)) {
        @unlink($temp);
        return false;
    }

    return true;
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
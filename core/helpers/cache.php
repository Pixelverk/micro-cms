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

function invalidate_cache(string $path = ''): void
{
    // If path is empty, delete all cache files
    if ($path === '') {
        $files = glob(STORAGE_PATH . '/cache/*.html');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        return;
    }

    // Sanitize path into cache filename
    $key = trim($path, '/') ?: 'home';
    $cacheFile = STORAGE_PATH . '/cache/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.html';

    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
}

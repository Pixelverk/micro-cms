<?php
// editor/_core/pages.php

declare(strict_types=1);

/**
 * Return a list of editable pages.
 *
 * [
 *   [
 *     'slug' => 'home',
 *     'path' => '/full/path/pages/home/index.html',
 *     'title' => 'Home'
 *   ]
 * ]
 */
function list_pages(): array
{
    $pages = [];

    if (!is_dir(PAGES_DIR)) {
        return $pages;
    }

    $dirs = scandir(PAGES_DIR);

    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') {
            continue;
        }

        $pageDir = PAGES_DIR . '/' . $dir;
        $indexFile = $pageDir . '/index.html';

        if (is_dir($pageDir) && file_exists($indexFile)) {
            $pages[] = [
                'slug'  => $dir,
                'path'  => $indexFile,
                'title' => extract_page_title($indexFile) ?? ucfirst($dir),
            ];
        }
    }

    return $pages;
}

/**
 * Load raw HTML of a page
 */
function load_page(string $slug): ?string
{
    $path = PAGES_DIR . '/' . $slug . '/index.html';

    if (!file_exists($path)) {
        return null;
    }

    return file_get_contents($path);
}

/**
 * Save HTML back to disk
 */
function save_page(string $slug, string $html): bool
{
    $path = PAGES_DIR . '/' . $slug . '/index.html';

    if (!file_exists($path)) {
        return false;
    }

    return file_put_contents($path, $html, LOCK_EX) !== false;
}

/**
 * Extract <title> from HTML
 */
function extract_page_title(string $filePath): ?string
{
    $html = file_get_contents($filePath);

    if (preg_match('/<title>(.*?)<\/title>/i', $html, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * Extract meta description
 */
function extract_meta_description(string $html): ?string
{
    if (preg_match(
        '/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/i',
        $html,
        $matches
    )) {
        return trim($matches[1]);
    }

    return null;
}
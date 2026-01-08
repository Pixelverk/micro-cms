<?php
// editor/_core/pages.php
// helpers for page crud

declare(strict_types=1);

/**
 * Return a list of editable pages.
 */
function list_pages(): array
{
    $pages = [];

    if (!is_dir(PAGES_DIR)) {
        return $pages;
    }

    $files = scandir(PAGES_DIR);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if ($ext !== 'json') {
            continue;
        }

        $pageFile = PAGES_DIR . '/' . $file;
        $slug = pathinfo($file, PATHINFO_FILENAME);

        $json = file_get_contents($pageFile);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            continue; // skip invalid JSON
        }

        $pages[] = [
            'slug'  => $slug,
            'path'  => $pageFile,
            'title' => $data['title'] ?? ucfirst($slug),
        ];
    }

    // Optional: sort alphabetically by title
    usort($pages, fn($a, $b) => strcmp($a['title'], $b['title']));

    return $pages;
}

/**
 * Load a page as an associative array from JSON
 */
function load_page(string $slug): ?array
{
    $path = PAGES_DIR . '/' . $slug . '.json';

    if (!file_exists($path)) {
        return null;
    }

    $json = file_get_contents($path);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return null; // invalid JSON
    }

    return $data;
}

/**
 * Save a page as JSON back to disk
 */
function save_page(string $slug, array $data): bool
{
    $path = PAGES_DIR . '/' . $slug . '.json';

    // Optional: validate structure here if you want

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false; // encoding failed
    }

    return file_put_contents($path, $json, LOCK_EX) !== false;
}
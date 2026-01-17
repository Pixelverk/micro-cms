<?php
// core/helpers/content.php

declare(strict_types=1);

/**
 * List all content items of a given type.
 *
 * @param string $type Content type (e.g., 'page', 'blog', etc.)
 * @return array List of content items with keys: slug, path, title
 */
function list_content(string $type): array
{
    $contentPath = STORAGE_PATH . "/content/{$type}";

    $items = [];

    if (!is_dir($contentPath)) {
        return $items;
    }

    $files = scandir($contentPath);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if ($ext !== 'json') {
            continue;
        }

        $filePath = $contentPath . '/' . $file;
        $slug = pathinfo($file, PATHINFO_FILENAME);

        $json = file_get_contents($filePath);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            continue;
        }

        $items[] = [
            'slug'  => $slug,
            'path'  => $filePath,
            'title' => $data['title'] ?? ucfirst($slug),
        ];
    }

    // Optional: sort alphabetically by title
    usort($items, fn($a, $b) => strcmp($a['title'], $b['title']));

    return $items;
}

/**
 * Load a content item of a given type as an associative array from JSON
 *
 * @param string $type Content type (e.g., 'page', 'blog')
 * @param string $slug Slug/filename without .json
 * @return array|null
 */
function load_content(string $type, string $slug): ?array
{
    $path = STORAGE_PATH . "/content/{$type}/{$slug}.json";

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
 * Save a content item of a given type to JSON
 *
 * @param string $type Content type
 * @param string $slug Slug/filename
 * @param array  $data Content array to save
 * @return bool
 */
function save_content(string $type, string $slug, array $data): bool
{
    $dir = STORAGE_PATH . "/content/{$type}";
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = $dir . '/' . $slug . '.json';

    // Optional: validate structure here

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false; // encoding failed
    }
    
    $result = file_put_contents($path, $json, LOCK_EX) !== false;
    save_sitemap();
    invalidate_cache($slug, $type);
    
    return $result;
}
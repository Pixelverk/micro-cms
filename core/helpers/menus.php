<?php
declare(strict_types=1);

/**
 * Return a list of editable menus.
 */
function list_menus(): array
{
    $pages = [];

    if (!is_dir(PAGES_PATH)) {
        return $pages;
    }

    $files = scandir(PAGES_PATH);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if ($ext !== 'json') {
            continue;
        }

        $pageFile = PAGES_PATH . '/' . $file;
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


function load_menus(): array
{
    if (!file_exists(MENUS_FILE)) {
        return [];
    }

    $json = file_get_contents(MENUS_FILE);
    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function save_menus(array $menus): bool
{
    $json = json_encode($menus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;

    invalidate_cache();

    return file_put_contents(MENUS_FILE, $json, LOCK_EX) !== false;
}


function get_menu(string $name): array
{
    $menus = load_menus();
    return $menus[$name] ?? [];
}

/**
 * Get the items of a menu by key.
 *
 * @param string $menuKey
 * @return array
 */
function get_menu_items(string $menuKey): array
{
    $menus = load_menus();
    return $menus[$menuKey]['items'] ?? [];
}

/**
 * Get the label of a menu by key.
 *
 * @param string $menuKey
 * @return string
 */
function get_menu_label(string $menuKey): string
{
    $menus = load_menus();
    return $menus[$menuKey]['label'] ?? '';
}
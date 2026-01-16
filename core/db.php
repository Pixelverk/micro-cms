<?php

// Connect SQLITE
function db(): PDO
{
    static $pdo;

    if (!$pdo) {
        $pdo = new PDO('sqlite:' . STORAGE_PATH . '/data.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    return $pdo;
}

/*
|--------------------------------------------------------------------------
| JSON Page Loader
|--------------------------------------------------------------------------
*/
function load_page_from_json(string $pageFile, string $slug): array
{
    $json = file_get_contents($pageFile);

    $pageData = json_decode($json, true);
    if (!is_array($pageData)) {
        throw new RuntimeException("Invalid JSON in {$pageFile}");
    }

    return [
        'id'         => $pageData['id'] ?? null,
        'type'       => 'page',
        'slug'       => $slug,
        'status'     => $pageData['status'] ?? 'published',
        'title'      => $pageData['title'] ?? '',
        'layout'     => $pageData['layout'] ?? config('defaults.layout'),
        'components' => $pageData['components'] ?? [],
        'meta'       => $pageData['meta'] ?? [],
        'updated_at' => $pageData['updated_at'] ?? filemtime($pageFile),
    ];
}


/* example JSON storage helpers I don't even use yet, might make them work in the future?
function load_data(string $file): array {
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    return json_decode($json, true) ?: [];
}

function save_data(string $file, array $data): void {
    $temp = $file . '.tmp';
    file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($temp, $file);
}

function get_item(string $file, string $key, $default = null) {
    $data = load_data($file);
    return $data[$key] ?? $default;
}

function set_item(string $file, string $key, $value): void {
    $data = load_data($file);
    $data[$key] = $value;
    save_data($file, $data);
} */
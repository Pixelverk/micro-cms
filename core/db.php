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

/**
 * Load a content item (page, blog post, etc.) as associative array from JSON
 */
function load_content_from_file(string $file, string $type, string $slug): array
{
    $json = file_get_contents($file);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON in {$file}");
    }

    $theme = theme_config();
    $settings = load_settings();
    $ctConfig = $theme['content_types'][$type] ?? [];

    $data['type']   = $type;
    $data['slug']   = $slug;

    $data['layout'] = $data['layout'] ?? $ctConfig['default_layout'] ?? $settings['default_layout'] ?? $theme['defaults']['layout'];
    $data['header'] = $data['header'] ?? $ctConfig['default_header'] ?? $settings['default_header'] ?? $theme['defaults']['header'];
    $data['footer'] = $data['footer'] ?? $ctConfig['default_footer'] ?? $settings['default_footer'] ?? $theme['defaults']['footer'];

    $data['status'] = '200';

    return $data;
}

function load_content_by_slug(string $slug): ?array
{
    $theme = theme_config();
    $settings = load_settings();

    $contentTypes = array_keys($theme['content_types'] ?? []);
    $prefixes = $settings['content_prefixes'] ?? [];

    foreach ($contentTypes as $type) {
        $prefix = $prefixes[$type] ?? '';

        if ($prefix) {
            // Skip this type if the slug does not start with the prefix
            if (!str_starts_with($slug, $prefix . '/')) {
                continue;
            }
            $relativeSlug = substr($slug, strlen($prefix) + 1);
        } else {
            $relativeSlug = $slug;
        }

        $file = STORAGE_PATH . "/content/{$type}/{$relativeSlug}.json";

        if (is_file($file)) {
            return load_content_from_file($file, $type, $relativeSlug);
        }
    }

    return null;
}

/**
 * Returns a default not-found structure for content type
 */
function not_found_content(string $type, string $slug): array
{
    return [
        'title'   => 'Not Found',
        'slug'    => $slug,
        'type'    => $type,
        'status'  => '404',
        'components' => [],
        'meta'    => [],
    ];
}
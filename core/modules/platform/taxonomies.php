<?php
declare(strict_types=1);






/*
|--------------------------------------------------------------------------
| Taxonomy declarations
|--------------------------------------------------------------------------
|
| A taxonomy is a named classification the theme declares. Core ships two
| (category and tag); a theme may override, remove or add to them under the
| manifest's `taxonomies` key. Which content types offer a taxonomy is the
| content type's business: it lists them under `taxonomies`.
|
| Terms are shared across the content types that offer the taxonomy; the link
| table records each item's own type.
*/

/**
 * The two taxonomies every install has unless the theme says otherwise.
 *
 * @return array<string, array<string, mixed>>
 */
function taxonomy_defaults(): array
{
    return [
        'category' => [
            'label'            => 'Category',
            'label_plural'     => 'Categories',
            // The built-in two keep translated labels; a theme-declared
            // taxonomy is shown as written.
            'label_key'        => 'taxonomy_category',
            'label_plural_key' => 'taxonomy_category_plural',
            'url_prefix'       => 'category',
            'multiple'         => false,
        ],
        'tag' => [
            'label'            => 'Tag',
            'label_plural'     => 'Tags',
            'label_key'        => 'taxonomy_tag',
            'label_plural_key' => 'taxonomy_tag_plural',
            'url_prefix'       => 'tag',
            'multiple'         => true,
        ],
    ];
}


/**
 * One declaration with every key filled in.
 *
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function taxonomy_normalize(string $name, array $config): array
{
    $label = (string) ($config['label'] ?? ucfirst($name));

    return [
        'name'             => $name,
        'label'            => $label,
        'label_plural'     => (string) ($config['label_plural'] ?? $label),
        'label_key'        => $config['label_key'] ?? null,
        'label_plural_key' => $config['label_plural_key'] ?? null,
        'url_prefix'       => (string) ($config['url_prefix'] ?? $name),
        'multiple'         => (bool) ($config['multiple'] ?? true),
        'layout'           => (string) ($config['layout'] ?? ''),
        'primary'          => !empty($config['primary']),
    ];
}


/**
 * Every declared taxonomy, keyed by name, with the defaults merged in.
 *
 * Pass $declared (a manifest's `taxonomies` key) to resolve a specific manifest
 * rather than the active theme's; the tests use that seam.
 *
 * @param array<string, mixed>|null $declared
 * @return array<string, array<string, mixed>>
 */
function theme_taxonomies(?array $declared = null): array
{
    static $cache = null;

    if ($declared === null) {
        if (is_array($cache)) {
            return $cache;
        }

        $manifest = theme_config();
        $declared = is_array($manifest['taxonomies'] ?? null) ? $manifest['taxonomies'] : [];
    }

    $taxonomies = [];

    foreach (taxonomy_defaults() as $defaultName => $default) {
        if (array_key_exists($defaultName, $declared) && $declared[$defaultName] === false) {
            continue;
        }

        $override = is_array($declared[$defaultName] ?? null) ? $declared[$defaultName] : [];
        $taxonomies[$defaultName] = taxonomy_normalize($defaultName, array_merge($default, $override));
    }

    foreach ($declared as $name => $config) {
        $name = (string) $name;

        if ($config === false || !is_array($config) || isset($taxonomies[$name])) {
            continue;
        }

        $taxonomies[$name] = taxonomy_normalize($name, $config);
    }

    if (func_num_args() === 0) {
        $cache = $taxonomies;
    }

    return $taxonomies;
}


/**
 * One taxonomy's declaration, or null when it is not declared.
 *
 * @return array<string, mixed>|null
 */
function taxonomy_config(string $name): ?array
{
    return theme_taxonomies()[$name] ?? null;
}


/**
 * The label shown for a taxonomy; the built-in two stay translated.
 */
function taxonomy_label(string $name, bool $plural = false): string
{
    $config = taxonomy_config($name);

    if ($config === null) {
        return $name;
    }

    $key = $plural ? ($config['label_plural_key'] ?? null) : ($config['label_key'] ?? null);

    if (is_string($key) && $key !== '') {
        return (string) admin_trans($key);
    }

    return (string) ($plural ? $config['label_plural'] : $config['label']);
}


/**
 * The taxonomy names a content type offers, in declaration order.
 *
 * @return list<string>
 */
function content_type_taxonomies(string $type): array
{
    $manifest = theme_config();
    $declared = $manifest['content_types'][$type]['taxonomies'] ?? [];

    if (!is_array($declared)) {
        return [];
    }

    $known = theme_taxonomies();
    $names = [];

    foreach ($declared as $name) {
        $name = (string) $name;

        if (isset($known[$name]) && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }

    return $names;
}


/**
 * The public URL of one term's archive.
 */
function taxonomy_url(string $name, string $slug): string
{
    $prefix = (string) (taxonomy_config($name)['url_prefix'] ?? $name);

    return url(trim($prefix . '/' . $slug, '/'));
}


/**
 * The taxonomy whose first term is the article "section" in SEO, or null.
 *
 * An explicit `primary` wins; otherwise the first single-term taxonomy (a
 * category, by default).
 */
function taxonomy_primary_name(): ?string
{
    $taxonomies = theme_taxonomies();

    foreach ($taxonomies as $name => $config) {
        if (!empty($config['primary'])) {
            return $name;
        }
    }

    foreach ($taxonomies as $name => $config) {
        if (empty($config['multiple'])) {
            return $name;
        }
    }

    return null;
}

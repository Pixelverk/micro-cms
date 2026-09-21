<?php
declare(strict_types=1);

/**
 * Return all menus with full info
 *
 * @return array Each menu: ['id', 'label', 'slug', 'items' => array, 'updated_at']
 */
function list_menus(): array
{
    $pdo = db();

    $stmt = $pdo->query("SELECT id, label, slug, items, updated_at FROM menus ORDER BY label ASC");
    $menus = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $menus[] = [
            'id'         => $row['id'],
            'label'      => $row['label'],
            'slug'       => $row['slug'],
            'items'      => json_decode($row['items'], true) ?? [],
            'updated_at' => (int)($row['updated_at'] ?? 0),
        ];
    }

    return $menus;
}

/**
 * Return menus keyed by slug
 *
 * @return array ['slug' => ['label' => ..., 'slug' => ..., 'items' => [...], 'updated_at' => ...]]
 */
function load_menus(): array
{
    $menus = [];
    foreach (list_menus() as $menu) {
        $menus[$menu['slug']] = $menu;
    }
    return $menus;
}

/**
 * Get a single menu by slug
 *
 * @param string $slug
 * @return array ['label' => '', 'slug' => '', 'items' => [], 'updated_at' => 0]
 */
function get_menu(string $slug): array
{
    $menus = load_menus();
    return $menus[$slug] ?? ['label' => '', 'slug' => $slug, 'items' => [], 'updated_at' => 0];
}

/**
 * Get the menu assigned to a theme location.
 *
 * The location is one of the menu_locations declared in theme.php. The admin's
 * assignment wins; with no assignment the slot falls back to a menu whose slug
 * matches the location, which is how the seeded main/footer menus work. A
 * location the theme does not declare, or one with neither an assignment nor a
 * same-named menu, resolves to an empty menu so the component renders nothing.
 *
 * Items come back ready to render: resolved URLs, hidden branches dropped, and
 * the active trail marked for the page being served.
 */
function get_menu_for_location(string $location): array
{
    $theme = theme_config();
    $locations = $theme['menu_locations'] ?? [];
    if (!array_key_exists($location, $locations)) {
        return ['label' => '', 'slug' => '', 'items' => [], 'updated_at' => 0];
    }

    $assignments = get_setting('menu_locations', []);
    $menuSlug = is_array($assignments) ? ($assignments[$location] ?? $location) : $location;

    $menu = get_menu((string) $menuSlug);
    $menu['items'] = menu_items_prepare($menu['items']);

    return $menu;
}

/*
|--------------------------------------------------------------------------
| Menu items
|--------------------------------------------------------------------------
|
| An item is {type, label, slug, target, hidden, children}. `type` is a content
| type key, 'url' for a hand-written link, or 'category'/'tag' for an archive.
| A content item also carries `content_id`, which is how a renamed page keeps
| its menu link: the id resolves to the row's *current* path. The stored slug is
| the fallback, so items written before ids existed — and packages, which never
| carry ids — still resolve.
|
*/

/**
 * Prepare a menu tree for rendering.
 *
 * @param list<array<string, mixed>> $items
 * @param string $currentPath Path being served; taken from the request when empty.
 * @return list<array<string, mixed>>
 */
function menu_items_prepare(array $items, string $currentPath = ''): array
{
    $context = ['rows' => [], 'terms' => []];

    $items = menu_items_resolve($items, $context);

    menu_items_mark_active($items, menu_path_normalise($currentPath !== '' ? $currentPath : menu_current_path()) ?? '');

    return $items;
}

/**
 * The path of the request being served, as a menu URL would name it.
 */
function menu_current_path(): string
{
    return (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
}

/**
 * Normalise a URL to the path two links can be compared by.
 *
 * Returns null when there is nothing to compare: absolute URLs, fragments and
 * non-http schemes are never the page you are on. A real path comes back
 * without its leading or trailing slash, so the site root is ''. The configured
 * base path is stripped, so a subfolder install compares its own paths.
 */
function menu_path_normalise(string $url): ?string
{
    $url = trim($url);

    if ($url === '' || $url === '#' || str_starts_with($url, '//')) {
        return null;
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
        return null;
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    $base = rtrim((string) (config('url') ?? ''), '/');

    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }

    return trim($path, '/');
}

/**
 * Resolve every item's URL, dropping hidden items and their branches.
 *
 * @param list<array<string, mixed>> $items
 * @param array{rows: array<string, list<array<string, mixed>>>, terms: array<string, bool>} $context
 * @param bool $keepHidden Keep hidden items, as the admin editor needs to.
 * @return list<array<string, mixed>>
 */
function menu_items_resolve(array $items, array &$context, bool $keepHidden = false): array
{
    $resolved = [];

    foreach ($items as $item) {
        if (!is_array($item) || (!$keepHidden && !empty($item['hidden']))) {
            continue;
        }

        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        $target   = menu_item_target($item, $context);

        $item['children'] = menu_items_resolve($children, $context, $keepHidden);
        $item['url']      = $target['url'];
        $item['broken']   = $target['broken'];

        $resolved[] = $item;
    }

    return $resolved;
}

/**
 * What one item points at.
 *
 * @param array<string, mixed> $item
 * @param array{rows: array<string, list<array<string, mixed>>>, terms: array<string, bool>} $context
 * @return array{url: string, broken: bool}
 */
function menu_item_target(array $item, array &$context): array
{
    $type = (string) ($item['type'] ?? 'url');
    $slug = trim((string) ($item['slug'] ?? ''));

    if ($type === 'url') {
        return ['url' => $slug !== '' ? $slug : '#', 'broken' => false];
    }

    if ($type === 'category' || $type === 'tag') {
        $key = $type . ':' . $slug;

        if (!isset($context['terms'][$key])) {
            $stmt = db()->prepare("SELECT COUNT(*) FROM taxonomy WHERE taxonomy_type = ? AND slug = ?");
            $stmt->execute([$type, $slug]);
            $context['terms'][$key] = (int) $stmt->fetchColumn() > 0;
        }

        return ['url' => url($type . '/' . $slug), 'broken' => !$context['terms'][$key]];
    }

    // Anything else has to be a content type the theme declares.
    if (!isset(theme_config()['content_types'][$type])) {
        return ['url' => '', 'broken' => true];
    }

    $row = null;
    $id  = (int) ($item['content_id'] ?? 0);

    if ($id > 0) {
        // Front visibility: a draft or trashed page is not something the public
        // navigation may link to.
        $row = load_content_by_id($id);

        if ($row !== null && (string) $row['type'] !== $type) {
            $row = null; // The id was reused by another type.
        }
    }

    if ($row === null && $slug !== '') {
        $context['rows'][$type] ??= content_path_rows($type);
        $candidate = menu_content_row_by_slug($type, $slug, $context['rows'][$type]);

        if ($candidate !== null) {
            $row = load_content_by_id((int) $candidate['id']);
        }
    }

    if ($row === null) {
        return ['url' => '', 'broken' => true];
    }

    $context['rows'][$type] ??= content_path_rows($type);

    return ['url' => content_url($row, $context['rows'][$type]), 'broken' => false];
}

/**
 * Find a content row by the slug an item stored: its own slug, or a full path.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>|null
 */
function menu_content_row_by_slug(string $type, string $slug, array $rows): ?array
{
    $want = trim($slug, '/');

    if ($want === '') {
        return null;
    }

    foreach ($rows as $row) {
        if ((string) $row['slug'] === $want) {
            return $row;
        }
    }

    foreach ($rows as $row) {
        if (build_full_slug($row, $rows) === $want) {
            return $row;
        }
    }

    return null;
}

/**
 * Mark the active trail: the page's own link, and the items it sits under.
 *
 * An item owns its subpaths, so /blog/ is active while reading /blog/a-post/,
 * but the site root only ever matches itself.
 *
 * @param list<array<string, mixed>> $items
 * @return bool Whether anything in this branch is active.
 */
function menu_items_mark_active(array &$items, string $current): bool
{
    $active = false;

    foreach ($items as &$item) {
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        $childActive = $children !== [] && menu_items_mark_active($children, $current);
        $item['children'] = $children;

        $target = menu_path_normalise((string) ($item['url'] ?? ''));

        // The root only ever matches itself; everything else also owns its
        // subpaths, so /blog/ is active while reading /blog/a-post/.
        $exact = $target !== null && $target === $current;
        $owns  = $target !== null && $target !== '' && str_starts_with($current, $target . '/');

        $item['current'] = $exact;
        $item['active']  = $exact || $owns || $childActive;

        $active = $active || $item['active'];
    }
    unset($item);

    return $active;
}

/**
 * Menu items without database ids, for a content package.
 *
 * Ids belong to the site that wrote them; the slug travels instead, and the
 * importer resolves it again on the other side.
 *
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function menu_items_strip_ids(array $items): array
{
    $clean = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        unset($item['content_id']);
        $item['children'] = menu_items_strip_ids(is_array($item['children'] ?? null) ? $item['children'] : []);

        $clean[] = $item;
    }

    return $clean;
}

/**
 * Re-resolve imported menu items to this install's ids.
 *
 * Called after the package's content is in place, so a menu keeps pointing at
 * its pages rather than at the ids of the site it came from.
 *
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function menu_items_attach_ids(array $items): array
{
    $types = array_keys(theme_config()['content_types'] ?? []);

    foreach ($items as &$item) {
        if (!is_array($item)) {
            continue;
        }

        $item['children'] = menu_items_attach_ids(is_array($item['children'] ?? null) ? $item['children'] : []);

        $type = (string) ($item['type'] ?? '');
        $slug = (string) ($item['slug'] ?? '');

        if ($slug !== '' && empty($item['content_id']) && in_array($type, $types, true)) {
            $row = menu_content_row_by_slug($type, $slug, content_path_rows($type));

            if ($row !== null) {
                $item['content_id'] = (int) $row['id'];
            }
        }
    }
    unset($item);

    return $items;
}

/**
 * Save or update a single menu
 *
 * @param array $menu ['label' => string, 'slug' => string, 'items' => array]
 * @return bool
 */
function save_menu(array $menu): bool
{
    $pdo = db();
    $now = time();

    $label = $menu['label'] ?? '';
    $slug  = $menu['slug'] ?? (sanitize_slug($label) ?: 'menu');
    $items = json_encode($menu['items'] ?? [], JSON_THROW_ON_ERROR);

    // Check existence
    $stmt = $pdo->prepare("SELECT id FROM menus WHERE slug = :slug LIMIT 1");
    $stmt->execute(['slug' => $slug]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        // Update
        $stmt = $pdo->prepare("
            UPDATE menus
            SET label = :label, items = :items, updated_at = :updated_at
            WHERE slug = :slug
        ");
        $success = $stmt->execute([
            'label'      => $label,
            'items'      => $items,
            'updated_at' => $now,
            'slug'       => $slug,
        ]);
    } else {
        // Insert
        $stmt = $pdo->prepare("
            INSERT INTO menus (label, slug, items, updated_at)
            VALUES (:label, :slug, :items, :updated_at)
        ");
        $success = $stmt->execute([
            'label'      => $label,
            'slug'       => $slug,
            'items'      => $items,
            'updated_at' => $now,
        ]);
    }

    invalidate_cache();
    return $success;
}

/**
 * Delete a menu by slug
 *
 * @param string $slug
 * @return bool
 */
function delete_menu(string $slug): bool
{
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM menus WHERE slug = :slug");
    $success = $stmt->execute(['slug' => $slug]);
    invalidate_cache();
    return $success;
}

/**
 * Normalise the posted menu item tree before it is saved.
 *
 * `type` is a content type key, 'url' for a hand-written link, or 'category' /
 * 'tag' for an archive. `content_id` is what lets a link follow a renamed page;
 * the slug beside it is the fallback. Neither `content_id` nor `hidden` is
 * written when it carries no information, so a plain item stays plain.
 */
function process_menu_items(array $items): array {
    $theme = theme_config();
    $kinds = array_merge(
        ['url', 'category', 'tag'],
        array_keys($theme['content_types'] ?? [])
    );

    $result = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = (string) ($item['type'] ?? 'url');

        // An item whose kind this install does not know is treated as a custom
        // link rather than silently dropped.
        if (!in_array($type, $kinds, true)) {
            $type = 'url';
        }

        $entry = [
            'type'     => $type,
            'label'    => (string) ($item['label'] ?? ''),
            'slug'     => (string) ($item['slug'] ?? ''),
            'target'   => ($item['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
            'children' => [],
        ];

        $contentId = (int) ($item['content_id'] ?? 0);

        if ($contentId > 0) {
            $entry['content_id'] = $contentId;
        }

        if (!empty($item['hidden'])) {
            $entry['hidden'] = true;
        }

        if (!empty($item['children']) && is_array($item['children'])) {
            $entry['children'] = process_menu_items($item['children']);
        }

        $result[] = $entry;
    }
    return $result;
}
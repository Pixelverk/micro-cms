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
    $slug  = $menu['slug'] ?? slugify($label);
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
 * Generate URL-safe slug from label
 */
function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    return strtolower($text ?: 'menu');
}
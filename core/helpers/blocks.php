<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reusable content blocks
|--------------------------------------------------------------------------
|
| A block is a named, saved component tree — a hero, a pricing table, a
| testimonial row — that editors can drop into any page instead of rebuilding
| it. Blocks reuse the normal component pipeline, so their CSS and JS are
| collected exactly like inline components.
|
| The tree is stored in the same shape as `content.body`:
|   [['type' => 'hero-section', 'props' => [...], 'children' => [...]]]
*/

/**
 * Depth limit for nested components inside a block.
 */
function blocks_max_depth(): int
{
    return 5;
}

/**
 * Are blocks enabled for this install?
 */
function blocks_enabled(): bool
{
    return (bool) config('features.blocks', true);
}

/**
 * Turn a label into a stable slug.
 */
function block_slug_from_label(string $label, ?int $ignoreId = null): string
{
    $slug = sanitize_slug($label);

    if ($slug === '') {
        $slug = 'block-' . bin2hex(random_bytes(3));
    }

    // Keep slugs unique: two blocks may share a label.
    $pdo = db();
    $candidate = $slug;
    $suffix = 2;

    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM blocks WHERE slug = :slug LIMIT 1");
        $stmt->execute(['slug' => $candidate]);
        $existing = $stmt->fetchColumn();

        if ($existing === false || ($ignoreId !== null && (int) $existing === $ignoreId)) {
            return $candidate;
        }

        $candidate = $slug . '-' . $suffix;
        $suffix++;
    }
}

/**
 * Every block, newest first.
 *
 * @return list<array<string, mixed>>
 */
function list_blocks(int $limit = 100): array
{
    $limit = max(1, min($limit, 300));

    $stmt = db()->prepare("
        SELECT b.*, u.username
        FROM blocks b
        LEFT JOIN users u ON u.id = b.created_by
        ORDER BY b.label COLLATE NOCASE ASC
        LIMIT {$limit}
    ");
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Decode the tree once here: every consumer wants the array, and passing
    // the raw JSON string around caused a miscount in the editor endpoint.
    foreach ($rows as &$row) {
        $row['tree'] = block_tree($row);
    }
    unset($row);

    return $rows;
}

function load_block(int $id): ?array
{
    $stmt = db()->prepare("
        SELECT b.*, u.username
        FROM blocks b
        LEFT JOIN users u ON u.id = b.created_by
        WHERE b.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row) {
        $row['tree'] = block_tree($row);
    }

    return $row;
}

function load_block_by_slug(string $slug): ?array
{
    $stmt = db()->prepare("SELECT * FROM blocks WHERE slug = :slug LIMIT 1");
    $stmt->execute(['slug' => $slug]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * The decoded component tree for a block.
 *
 * @return list<array<string, mixed>>
 */
function block_tree(array $block): array
{
    $tree = $block['tree'] ?? [];

    if (is_string($tree)) {
        $tree = json_decode($tree, true);
    }

    return is_array($tree) ? $tree : [];
}

/**
 * Sanitise a component tree coming from the editor (or a JSON payload).
 *
 * Drops entries without a component name, removes props that are not scalar
 * or arrays, and enforces a nesting depth so a malformed block cannot take
 * the renderer down.
 *
 * @return list<array<string, mixed>>
 */
function blocks_normalise_tree(mixed $tree, string $contentType = 'page', int $depth = 0): array
{
    if (!is_array($tree) || $depth > blocks_max_depth()) {
        return [];
    }

    $theme = theme_config();
    $available = $theme['content_types'][$contentType]['available_components'] ?? [];

    $clean = [];

    foreach ($tree as $component) {
        if (!is_array($component)) {
            continue;
        }

        $type = (string) ($component['type'] ?? $component['component'] ?? '');
        $type = preg_replace('/[^a-z0-9\-_]/i', '', $type) ?? '';

        if ($type === '') {
            continue;
        }

        // Only components the theme knows about may be stored. Unknown ones
        // are dropped rather than rendered as a warning box forever.
        if (!component_exists($type)) {
            continue;
        }

        $props = [];
        if (!empty($component['props']) && is_array($component['props'])) {
            foreach ($component['props'] as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                if (is_scalar($value) || $value === null || is_array($value)) {
                    $props[$key] = $value;
                }
            }
        }

        $clean[] = [
            'type'     => $type,
            'props'    => $props,
            'children' => blocks_normalise_tree($component['children'] ?? [], $contentType, $depth + 1),
        ];
    }

    return $clean;
}

/**
 * Is there a component file for this name?
 */
function component_exists(string $name): bool
{
    static $cache = [];

    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }

    return $cache[$name] = is_file(theme("components/{$name}.php"))
        || is_file(CORE_PATH . "/components/{$name}.php");
}

/**
 * Count the components in a tree (including children).
 */
function blocks_count_components(array $tree): int
{
    $count = 0;

    foreach ($tree as $component) {
        $count++;
        $count += blocks_count_components($component['children'] ?? []);
    }

    return $count;
}

/**
 * Create or update a block.
 *
 * @param array<string, mixed> $input ['label' => string, 'description' => string, 'tree' => array, 'content_type' => string]
 * @return array{ok: bool, id?: int, slug?: string, errors?: array<string, string>}
 */
function save_block(array $input, ?int $id = null): array
{
    $pdo = db();
    $now = time();

    $errors = [];

    $label = trim((string) ($input['label'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $contentType = (string) ($input['content_type'] ?? 'page');

    if ($label === '') {
        $errors['label'] = 'Give the block a name.';
    } elseif (mb_strlen($label) > 80) {
        $errors['label'] = 'Block names are limited to 80 characters.';
    }

    $theme = theme_config();
    if (!isset($theme['content_types'][$contentType])) {
        $contentType = array_key_first($theme['content_types'] ?? []) ?: 'page';
    }

    $tree = blocks_normalise_tree($input['tree'] ?? [], $contentType);

    if (!$tree) {
        $errors['tree'] = 'A block needs at least one component.';
    } elseif (blocks_count_components($tree) > 60) {
        $errors['tree'] = 'That block has too many components (limit 60).';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $slug = block_slug_from_label($label, $id);

    if ($id !== null) {
        $stmt = $pdo->prepare("
            UPDATE blocks
            SET slug = :slug, label = :label, description = :description, tree = :tree, updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->execute([
            'id'          => $id,
            'slug'        => $slug,
            'label'       => $label,
            'description' => $description !== '' ? $description : null,
            'tree'        => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'  => $now,
        ]);

        log_activity('block.updated', 'block', $id, $label, ['components' => blocks_count_components($tree)]);

        return ['ok' => true, 'id' => $id, 'slug' => $slug];
    }

    $stmt = $pdo->prepare("
        INSERT INTO blocks (slug, label, description, tree, created_by, created_at, updated_at)
        VALUES (:slug, :label, :description, :tree, :created_by, :created_at, :updated_at)
    ");
    $stmt->execute([
        'slug'        => $slug,
        'label'       => $label,
        'description' => $description !== '' ? $description : null,
        'tree'        => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_by'  => function_exists('current_user_id') ? current_user_id() : null,
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);

    $newId = (int) $pdo->lastInsertId();

    log_activity('block.created', 'block', $newId, $label, ['components' => blocks_count_components($tree)]);

    return ['ok' => true, 'id' => $newId, 'slug' => $slug];
}

function delete_block(int $id): bool
{
    $block = load_block($id);

    if (!$block) {
        return false;
    }

    db()->prepare("DELETE FROM blocks WHERE id = :id")->execute(['id' => $id]);

    log_activity('block.deleted', 'block', $id, (string) $block['label'], []);

    return true;
}

/**
 * Render a saved block inside a page (theme-facing helper).
 */
function render_block(string $slug, array $page = [], array &$collectedJs = [], array &$collectedCss = []): void
{
    $block = load_block_by_slug($slug);

    if (!$block) {
        debug_log("render_block(): no block with slug '{$slug}'");

        return;
    }

    render_components(block_tree($block), $page, $collectedJs, $collectedCss);
}

/**
 * Blocks offered for a given content type, filtered to components the type
 * actually allows.
 *
 * @return list<array<string, mixed>>
 */
function blocks_for_type(string $contentType): array
{
    $theme = theme_config();
    $allowed = $theme['content_types'][$contentType]['available_components'] ?? [];

    $blocks = [];

    foreach (list_blocks() as $block) {
        $tree = block_tree($block);

        // A block is offered when at least one of its components is allowed.
        $usable = false;
        foreach ($tree as $component) {
            if (in_array($component['type'] ?? '', $allowed, true)) {
                $usable = true;
                break;
            }
        }

        if ($usable || !$allowed) {
            $block['tree'] = $tree;
            $block['component_count'] = blocks_count_components($tree);
            $blocks[] = $block;
        }
    }

    return $blocks;
}

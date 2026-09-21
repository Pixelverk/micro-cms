<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taxonomy terms
|--------------------------------------------------------------------------
|
| Reading an item's terms and the admin's term CRUD. The declarations
| themselves — which taxonomies exist, their labels, prefixes and layouts —
| live in core/modules/platform/taxonomies.php.
*/

/**
 * The terms on a page, keyed by declared taxonomy name (empty lists included).
 *
 * A page array already carries them; an array without that key is resolved
 * from the database, so a component can accept either.
 *
 * @param array<string, mixed> $page
 * @return array<string, list<array<string, mixed>>>
 */
function content_taxonomies(array $page): array
{
    if (isset($page['taxonomies']) && is_array($page['taxonomies'])) {
        return $page['taxonomies'];
    }

    $type = (string) ($page['type'] ?? '');
    $id   = (int) ($page['id'] ?? 0);

    if ($type !== '' && $id > 0) {
        return load_taxonomies_for_content($type, $id);
    }

    return [];
}


/**
 * The terms linked to one content item, keyed by declared taxonomy name.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function load_taxonomies_for_content(string $type, int $id): array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT t.*
        FROM taxonomy t
        JOIN taxonomy_term_relationships r
            ON r.taxonomy_id = t.id
        WHERE r.content_type = ?
        AND r.content_id = ?
        ORDER BY t.name
    ");

    $stmt->execute([$type, $id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];

    foreach (array_keys(theme_taxonomies()) as $name) {
        $out[$name] = [];
    }

    // A term whose taxonomy the theme no longer declares is left out of the
    // page array; its relationship stays in the database.
    foreach ($rows as $row) {
        $kind = (string) $row['taxonomy_type'];

        if (array_key_exists($kind, $out)) {
            $out[$kind][] = $row;
        }
    }

    return $out;
}




/**
 * Create or update a taxonomy term from posted fields.
 *
 * Categories and tags share one flow; only the taxonomy_type and the
 * user-facing wording differ. Tags additionally require a content type.
 *
 * @param string $kind 'category' or 'tag'
 * @param array<string, mixed> $post
 */
function save_taxonomy(string $kind, array $post): void
{
    $pdo = db();
    $now = time();

    $id          = !empty($post['id']) ? (int) $post['id'] : null;
    $name        = trim((string) ($post['name'] ?? ''));
    $slug        = trim((string) ($post['slug'] ?? ''));
    $description = trim((string) ($post['description'] ?? ''));

    if ($name === '') {
        redirect_with_toast('taxonomy', 'error', 'Name is required.', ['type' => $kind]);
    }

    $slug = sanitize_slug($slug !== '' ? $slug : $name) ?: $kind;

    // Keep the slug unique within this taxonomy type.
    $baseSlug = $slug;
    $counter  = 1;

    while (true) {
        $sql  = "SELECT id FROM taxonomy WHERE taxonomy_type = ? AND slug = ?";
        $args = [$kind, $slug];

        if ($id) {
            $sql   .= " AND id != ?";
            $args[] = $id;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        if (!$stmt->fetch()) {
            break;
        }

        $slug = $baseSlug . '-' . $counter++;
    }

    if ($id) {
        // The old slug, so a changed archive URL can keep working.
        $oldStmt = $pdo->prepare("SELECT slug FROM taxonomy WHERE id = ? AND taxonomy_type = ?");
        $oldStmt->execute([$id, $kind]);
        $oldSlug = (string) $oldStmt->fetchColumn();

        // content_type is a retired column: terms are shared across the content
        // types that offer the taxonomy, so it is written empty and never read.
        $stmt = $pdo->prepare("
            UPDATE taxonomy SET
                name         = ?,
                slug         = ?,
                description  = ?,
                content_type = '',
                updated_at   = ?
            WHERE id = ?
            AND taxonomy_type = ?
        ");

        $stmt->execute([$name, $slug, $description, $now, $id, $kind]);

        if ($oldSlug !== '' && $oldSlug !== $slug) {
            redirect_record_taxonomy_slug_change($kind, $oldSlug, $slug);
        }

        $message = taxonomy_label($kind) . ' updated.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO taxonomy (
                taxonomy_type,
                name,
                slug,
                content_type,
                description,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, '', ?, ?, ?)
        ");

        $stmt->execute([$kind, $name, $slug, $description, $now, $now]);

        $message = taxonomy_label($kind) . ' created.';
    }

    log_activity($id ? 'taxonomy.updated' : 'taxonomy.created', 'taxonomy', $id ?: null, $name, []);

    invalidate_cache();
    // Archive URLs and their lastmod dates are in the sitemap.
    save_sitemap();

    redirect_with_toast('taxonomy', 'success', $message, ['type' => $kind]);
}




/**
 * Delete a taxonomy term and its content relationships.
 *
 * @param string $kind the taxonomy name
 */
function remove_taxonomy(string $kind, int $id): void
{
    if ($id <= 0) {
        redirect_with_toast('taxonomy', 'error', 'Invalid taxonomy.', ['type' => $kind]);
    }

    $name = taxonomy_delete($kind, $id);

    if ($name === '') {
        redirect_with_toast('taxonomy', 'error', taxonomy_label($kind) . ' not found.', ['type' => $kind]);
    }

    // A term shows on its archive page and on every item that carries it.
    invalidate_cache();
    save_sitemap();

    redirect_with_toast('taxonomy', 'success', taxonomy_label($kind) . ' "' . $name . '" deleted.', ['type' => $kind]);
}




/**
 * Delete one term and the links to it.
 *
 * The work behind the row button, the bulk toolbar and anything else that
 * removes a term, so those paths cannot drift. It does not redirect, which is
 * what lets a bulk run call it in a loop. Returns the term's name, or '' when
 * there was nothing to delete.
 */
function taxonomy_delete(string $kind, int $id): string
{
    if ($id <= 0) {
        return '';
    }

    $pdo  = db();
    $stmt = $pdo->prepare("SELECT id, name FROM taxonomy WHERE id = ? AND taxonomy_type = ?");
    $stmt->execute([$id, $kind]);
    $term = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$term) {
        return '';
    }

    // Relationships first, so no orphans survive the term.
    $pdo->prepare("DELETE FROM taxonomy_term_relationships WHERE taxonomy_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM taxonomy WHERE id = ? AND taxonomy_type = ?")->execute([$id, $kind]);

    log_activity('taxonomy.deleted', 'taxonomy', $id, (string) $term['name'], ['kind' => $kind]);

    return (string) $term['name'];
}




/**
 * Delete several terms of one kind.
 *
 * Ids that no longer resolve are counted as skipped rather than failing the
 * run: a term somebody else removed first is not an error.
 *
 * @param list<int> $ids
 * @return array{removed: int, skipped: int}
 */
function bulk_delete_taxonomies(string $kind, array $ids): array
{
    $removed = 0;
    $skipped = 0;

    foreach ($ids as $id) {
        if (taxonomy_delete($kind, (int) $id) !== '') {
            $removed++;
        } else {
            $skipped++;
        }
    }

    // Once for the run: the cache holds archive pages and listings that carried
    // these terms, and the sitemap listed their archives.
    if ($removed > 0) {
        invalidate_cache();
        save_sitemap();
    }

    return ['removed' => $removed, 'skipped' => $skipped];
}





/**
 * Items per page in a taxonomy archive.
 *
 * A fixed default rather than a query parameter, so `?per_page=1000` cannot be
 * used to make the site render an unbounded listing.
 */
function taxonomy_per_page(): int
{
    return 10;
}



// load taxonomy archive data
function load_taxonomy_archive(string $taxonomyType, string $slug): array
{
    $pdo = db();

    // ----------------------------
    // Load the taxonomy term
    // ----------------------------
    $stmt = $pdo->prepare("
        SELECT *
        FROM taxonomy
        WHERE taxonomy_type = :type
          AND slug = :slug
        LIMIT 1
    ");
    $stmt->execute([
        'type' => $taxonomyType,
        'slug' => $slug,
    ]);

    $taxonomy = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$taxonomy) {
        return load_fallback_404();
    }

    // ----------------------------
    // Load one page of the linked items
    // ----------------------------
    // Front-end visibility applies, so a draft linked to this term stays hidden
    // exactly as it is everywhere else. The ordering is a total order, so
    // LIMIT/OFFSET cannot duplicate or skip a row between pages.
    $pageNumber = pagination_current_page();
    $perPage    = taxonomy_per_page();

    $count = $pdo->prepare("
        SELECT COUNT(*)
        FROM content c
        INNER JOIN taxonomy_term_relationships ttr
            ON ttr.content_id = c.id
           AND ttr.content_type = c.type
        WHERE ttr.taxonomy_id = :taxId
          AND c.status = 'published'
          AND c.published_at IS NOT NULL
          AND c.published_at <= :now
          AND c.deleted_at IS NULL
    ");
    $count->execute(['taxId' => $taxonomy['id'], 'now' => time()]);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.*
        FROM content c
        INNER JOIN taxonomy_term_relationships ttr
            ON ttr.content_id = c.id
           AND ttr.content_type = c.type
        WHERE ttr.taxonomy_id = :taxId
          AND c.status = 'published'
          AND c.published_at IS NOT NULL
          AND c.published_at <= :now
          AND c.deleted_at IS NULL
        ORDER BY c.published_at DESC, c.id DESC
        LIMIT {$perPage} OFFSET " . pagination_offset($pageNumber, $perPage) . "
    ");
    $stmt->execute(['taxId' => $taxonomy['id'], 'now' => time()]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // decode JSON fields and attach taxonomies to each item
    foreach ($items as &$item) {
        $item['meta'] = $item['meta'] ? json_decode($item['meta'], true) : [];
        $item['body'] = $item['body'] ? json_decode($item['body'], true) : [];
        $taxes = load_taxonomies_for_content($item['type'], $item['id']);
        $item['taxonomies'] = $taxes;
        $item['categories'] = $taxes['category'] ?? [];
        $item['tags']       = $taxes['tag'] ?? [];
    }
    unset($item);

    // ----------------------------
    // Determine layout
    // ----------------------------
    // Terms are shared, so an archive has no single content type: the layout
    // comes from the taxonomy declaration, then the built-in fallback.
    $theme  = theme_config();
    $config = taxonomy_config($taxonomyType) ?? [];

    $layout = (string) ($config['layout'] ?? '');
    $layout = $layout !== '' ? $layout : 'taxonomy';

    // ----------------------------
    // Build page array
    // ----------------------------
    $page = [
        'id'         => null,
        'type'       => $taxonomyType,
        'slug'       => $slug,
        'status'     => 'published',
        'title'      => $taxonomy['name'],
        'layout'     => $layout,
        'taxonomy'   => $taxonomy,
        'taxonomy_config' => $config,
        'items'      => $items,
        'pagination' => pagination_result($items, $total, $pageNumber, $perPage, url($taxonomyType . '/' . $slug)),
        'components' => [], // not used for archive layouts
        'updated_at' => time(),
    ];

    // Optional: header/footer defaults
    $settings = load_settings();
    $page['header'] = $settings['default_header'] ?? $theme['defaults']['header'] ?? 'site-header';
    $page['footer'] = $settings['default_footer'] ?? $theme['defaults']['footer'] ?? 'site-footer';

    return $page;
}

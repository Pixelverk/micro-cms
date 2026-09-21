<?php
declare(strict_types=1);




// taxonomies
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

    $out = [
        'category' => [],
        'tag'      => [],
    ];

    foreach ($rows as $row) {
        $out[$row['taxonomy_type']][] = $row;
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
    $contentType = trim((string) ($post['content_type'] ?? ''));

    if ($name === '') {
        redirect_with_toast($kind, 'error', 'Name is required.');
    }

    if ($kind === 'tag' && $contentType === '') {
        redirect_with_toast($kind, 'error', 'Content type is required.');
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
        $stmt = $pdo->prepare("
            UPDATE taxonomy SET
                name         = ?,
                slug         = ?,
                description  = ?,
                content_type = ?,
                updated_at   = ?
            WHERE id = ?
            AND taxonomy_type = ?
        ");

        $stmt->execute([$name, $slug, $description, $contentType, $now, $id, $kind]);

        $message = ucfirst($kind) . ' updated.';
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
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([$kind, $name, $slug, $contentType, $description, $now, $now]);

        $message = ucfirst($kind) . ' created.';
    }

    log_activity($id ? 'taxonomy.updated' : 'taxonomy.created', 'taxonomy', $id ?: null, $name, []);

    invalidate_cache();

    redirect_with_toast($kind, 'success', $message);
}



/**
 * Delete a taxonomy term and its content relationships.
 *
 * @param string $kind 'category' or 'tag'
 */
function remove_taxonomy(string $kind, int $id): void
{
    if ($id <= 0) {
        redirect_with_toast($kind, 'error', 'Invalid ' . $kind . '.');
    }

    $name = taxonomy_delete($kind, $id);

    if ($name === '') {
        redirect_with_toast($kind, 'error', ucfirst($kind) . ' not found.');
    }

    // A term shows on its archive page and on every item that carries it.
    invalidate_cache();

    redirect_with_toast($kind, 'success', ucfirst($kind) . ' "' . $name . '" deleted.');
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
    // these terms.
    if ($removed > 0) {
        invalidate_cache();
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

    $contentType = $taxonomy['content_type'];

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
        $item['categories'] = [];
        $item['tags']       = [];

        $taxes = load_taxonomies_for_content($item['type'], $item['id']);
        $item['categories'] = $taxes['category'];
        $item['tags']       = $taxes['tag'];
    }
    unset($item);

    // ----------------------------
    // Determine layout
    // ----------------------------
    $theme = theme_config();
    $contentTypes = $theme['content_types'] ?? [];
    $ctConfig = $contentTypes[$contentType] ?? [];

    $layout = $ctConfig['taxonomy_layout'] ?? 'taxonomy';

    // ----------------------------
    // Build page array
    // ----------------------------
    $page = [
        'id'         => null,
        'type'       => $contentType,
        'slug'       => $slug,
        'status'     => 'published',
        'title'      => $taxonomy['name'],
        'layout'     => $layout,
        'taxonomy'   => $taxonomy,
        'items'      => $items,
        'pagination' => pagination_result($items, $total, $pageNumber, $perPage, url($taxonomyType . '/' . $slug)),
        'components' => [], // not used for archive layouts
        'updated_at' => time(),
    ];

    // Optional: header/footer defaults
    $settings = load_settings();
    $page['header'] = $ctConfig['default_header'] ?? $settings['default_header'] ?? $theme['defaults']['header'] ?? 'site-header';
    $page['footer'] = $ctConfig['default_footer'] ?? $settings['default_footer'] ?? $theme['defaults']['footer'] ?? 'site-footer';

    return $page;
}

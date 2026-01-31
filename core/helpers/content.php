<?php
declare(strict_types=1);

/**
 * List content items of a given type
 *
 * @param string $type
 * @return array
 */
function list_content(string $type): array
{
    $pdo = db();
    $now = time();

    // Base query
    $sql = "
        SELECT id, slug, title, parent_id, status, published_at, created_at, updated_at, scheduled_at
        FROM content
        WHERE type = :type
    ";

    $params = ['type' => $type];

    // If frontend, only show published items that are due
    if (!is_logged_in()) {
        $sql .= " AND status = 'published' AND published_at <= :now";
        $params['now'] = $now;
    }

    $sql .= " ORDER BY title COLLATE NOCASE ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}


/**
 * Load a content item of a given type by slug
 *
 * @param string $type
 * @param string $slug
 * @param int|null $parentId
 * @return array|null
 */
function load_content(string $type, string $slug, ?int $parentId = null): ?array
{
    $pdo = db();
    $now = time();

    $sql = "
        SELECT *
        FROM content
        WHERE type = :type
          AND slug = :slug
          AND parent_id " . ($parentId === null ? "IS NULL" : "= :parent_id") . "
        LIMIT 1
    ";

    $params = [
        'type' => $type,
        'slug' => $slug,
    ];

    if ($parentId !== null) {
        $params['parent_id'] = $parentId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) return null;

    // Frontend visibility check
    if (!is_logged_in()) {
        if ($row['status'] !== 'published' || (int)$row['published_at'] > $now) {
            return null; // hide drafts or scheduled content
        }
    }

    return [
        'id'           => (int) $row['id'],
        'parent_id'    => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
        'type'         => $row['type'],
        'slug'         => $row['slug'],
        'title'        => $row['title'],
        'status'       => $row['status'],
        'layout'       => $row['layout'],
        'header'       => $row['header'],
        'footer'       => $row['footer'],
        'meta'         => $row['meta'] ? json_decode($row['meta'], true) : [],
        'body'         => $row['body'] ? json_decode($row['body'], true) : [],
        'published_at' => $row['published_at'],
        'scheduled_at' => $row['scheduled_at'],
        'created_at'   => $row['created_at'],
        'updated_at'   => $row['updated_at'],
    ];
}

/**
 * Load a content item by ID
 */
function load_content_by_id(int $id): ?array
{
    $pdo = db();
    $now = time();

    $stmt = $pdo->prepare("SELECT * FROM content WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return null;

    // ----------------------------
    // Frontend visibility check
    // ----------------------------
    if (!is_logged_in()) {
        if ($row['status'] !== 'published' || (int)$row['published_at'] > $now) {
            return null;
        }
    }

    // ----------------------------
    // Decode JSON first
    // ----------------------------
    $meta = $row['meta'] ? json_decode($row['meta'], true) : [];
    $body = $row['body'] ? json_decode($row['body'], true) : [];

    // ----------------------------
    // Load taxonomies
    // ----------------------------
    $tax = load_taxonomies_for_content($row['type'], (int)$row['id']);

    // ----------------------------
    // Return normalized object
    // ----------------------------
    return [
        'id'           => (int)$row['id'],
        'parent_id'    => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
        'type'         => $row['type'],
        'slug'         => $row['slug'],
        'title'        => $row['title'],
        'status'       => $row['status'],
        'layout'       => $row['layout'],
        'header'       => $row['header'],
        'footer'       => $row['footer'],
        'meta'         => $meta,
        'body'         => $body,
        'published_at' => $row['published_at'],
        'scheduled_at' => $row['scheduled_at'],
        'created_at'   => $row['created_at'],
        'updated_at'   => $row['updated_at'],
        'categories'   => $tax['category'],
        'tags'         => $tax['tag'],
    ];
}

/**
 * Save or update a content item to the database
 *
 * @param string $type
 * @param string $slug
 * @param array  $data
 * @param int|null $id Optional ID for existing content
 * @return int|null The ID of the saved content
 */
function save_content(string $type, string $slug, array $data, ?int $id = null): ?int
{
    $pdo = db();
    $now = time();

    // Ensure required structures exist
    $data['meta'] ??= [];
    $data['body'] ??= [];

    // ----------------------------
    // Scheduled publishing
    // ----------------------------
    $scheduledAt = $data['scheduled_at'] ?? null;

    if ($scheduledAt !== null && $scheduledAt !== '') {
        if (is_numeric($scheduledAt)) {
            $scheduledAt = (int)$scheduledAt;
        } elseif (is_string($scheduledAt)) {
            $scheduledAt = strtotime($scheduledAt) ?: null;
        } else {
            $scheduledAt = null;
        }
    } else {
        $scheduledAt = null;
    }

    // ----------------------------
    // Determine actual status
    // ----------------------------
    $status = $data['status'] ?? 'draft';

    // If published but scheduled in future, mark as 'scheduled'
    if ($status === 'published' && $scheduledAt && $scheduledAt > $now) {
        $status = 'scheduled';
    }

    // Only set published_at if actually published
    $publishedAt = $status === 'published'
        ? ($data['published_at'] ?? $now)
        : null;

    // Parent handling (NULL = top-level)
    $parentId = $data['parent_id'] ?? null;
    if ($parentId === '') {
        $parentId = null;
    }

    // ----------------------------
    // Determine whether to update or insert
    // ----------------------------
    if ($id) {
        $existingId = $id;
    } else {
        $sql = "
            SELECT id
            FROM content
            WHERE type = :type
              AND slug = :slug
              AND parent_id " . ($parentId === null ? "IS NULL" : "= :parent_id") . "
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $params = ['type' => $type, 'slug' => $slug];
        if ($parentId !== null) {
            $params['parent_id'] = $parentId;
        }
        $stmt->execute($params);
        $existingId = $stmt->fetchColumn();
    }

    if ($existingId) {
        // ----------------------------
        // UPDATE
        // ----------------------------
        $stmt = $pdo->prepare("
            UPDATE content SET
                parent_id     = :parent_id,
                title         = :title,
                status        = :status,
                layout        = :layout,
                header        = :header,
                footer        = :footer,
                meta          = :meta,
                body          = :body,
                published_at  = :published_at,
                scheduled_at  = :scheduled_at,
                updated_at    = :updated_at
            WHERE id = :id
        ");
        $stmt->execute([
            'id'           => $existingId,
            'parent_id'    => $parentId,
            'title'        => $data['title'],
            'status'       => $status,
            'layout'       => $data['layout'] ?? null,
            'header'       => $data['header'] ?? null,
            'footer'       => $data['footer'] ?? null,
            'meta'         => json_encode($data['meta'], JSON_THROW_ON_ERROR),
            'body'         => json_encode($data['body'], JSON_THROW_ON_ERROR),
            'published_at' => $publishedAt,
            'scheduled_at' => $scheduledAt,
            'updated_at'   => $now,
        ]);

        $idToReturn = (int)$existingId;

    } else {
        // ----------------------------
        // INSERT
        // ----------------------------
        $stmt = $pdo->prepare("
            INSERT INTO content (
                type,
                parent_id,
                slug,
                title,
                status,
                layout,
                header,
                footer,
                meta,
                body,
                published_at,
                scheduled_at,
                created_at,
                updated_at
            ) VALUES (
                :type,
                :parent_id,
                :slug,
                :title,
                :status,
                :layout,
                :header,
                :footer,
                :meta,
                :body,
                :published_at,
                :scheduled_at,
                :created_at,
                :updated_at
            )
        ");
        $stmt->execute([
            'type'         => $type,
            'parent_id'    => $parentId,
            'slug'         => $slug,
            'title'        => $data['title'],
            'status'       => $status,
            'layout'       => $data['layout'] ?? null,
            'header'       => $data['header'] ?? null,
            'footer'       => $data['footer'] ?? null,
            'meta'         => json_encode($data['meta'], JSON_THROW_ON_ERROR),
            'body'         => json_encode($data['body'], JSON_THROW_ON_ERROR),
            'published_at' => $publishedAt,
            'scheduled_at' => $scheduledAt,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $idToReturn = (int)$pdo->lastInsertId();
    }

    // ----------------------------
    // Housekeeping
    // ----------------------------
    invalidate_cache($slug, $type);
    save_sitemap();

    return $idToReturn;
}

/**
 * Build full slug path recursively for a single page
 */
function build_full_slug(array $item, array $allItems): string {
    $path = [$item['slug']];
    $parentId = $item['parent_id'] ?? null;
    while ($parentId) {
        $found = false;
        foreach ($allItems as $p) {
            if ($p['id'] === $parentId) {
                array_unshift($path, $p['slug']);
                $parentId = $p['parent_id'];
                $found = true;
                break;
            }
        }
        if (!$found) break; // just in case
    }
    return implode('/', $path);
}

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
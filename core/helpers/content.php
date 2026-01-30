<?php
declare(strict_types=1);

/**
 * List *specific fields* from all content items of a given type
 *
 * @param string $type Content type (e.g., 'page', 'blog_post')
 * @return array List of content items with keys: slug, title, status, published_at, created_at, updated_at
 */
function list_content(string $type): array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, slug, title, parent_id, status, published_at, created_at, updated_at
        FROM content
        WHERE type = :type
        ORDER BY title COLLATE NOCASE ASC
    ");
    $stmt->execute(['type' => $type]);

    return $stmt->fetchAll() ?: [];
}

/**
 * Load a content item of a given type by slug
 *
 * @param string $type
 * @param string $slug
 * @return array|null
 */
function load_content(string $type, string $slug, ?int $parentId = null): ?array
{
    $pdo = db();

    $sql = "
        SELECT *
        FROM content
        WHERE type = :type
          AND slug = :slug
          AND parent_id " . ($parentId === null ? "IS NULL" : "= :parent_id") . "
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $params = [
        'type' => $type,
        'slug' => $slug,
    ];

    if ($parentId !== null) {
        $params['parent_id'] = $parentId;
    }

    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) return null;

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
        'body'         => json_decode($row['body'], true),
        'published_at' => $row['published_at'],
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

    $stmt = $pdo->prepare("SELECT * FROM content WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if (!$row) return null;

    return [
        'id'         => (int) $row['id'],
        'parent_id'  => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
        'type'       => $row['type'],
        'slug'       => $row['slug'],
        'title'      => $row['title'],
        'status'     => $row['status'],
        'layout'     => $row['layout'],
        'header'     => $row['header'],
        'footer'     => $row['footer'],
        'meta'       => $row['meta'] ? json_decode($row['meta'], true) : [],
        'body'       => json_decode($row['body'], true),
        'published_at' => $row['published_at'],
        'created_at'   => $row['created_at'],
        'updated_at'   => $row['updated_at'],
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

    $status = $data['status'] ?? 'draft';
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
        // Force update by ID
        $existingId = $id;
    } else {
        // Identity = (type, parent_id, slug)
        $sql = "
            SELECT id
            FROM content
            WHERE type = :type
              AND slug = :slug
              AND parent_id " . ($parentId === null ? "IS NULL" : "= :parent_id") . "
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);

        $params = [
            'type' => $type,
            'slug' => $slug,
        ];

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
                parent_id    = :parent_id,
                title        = :title,
                status       = :status,
                layout       = :layout,
                header       = :header,
                footer       = :footer,
                meta         = :meta,
                body         = :body,
                published_at = :published_at,
                updated_at   = :updated_at
            WHERE id = :id
        ");

        $result = $stmt->execute([
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
                :created_at,
                :updated_at
            )
        ");

        $result = $stmt->execute([
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
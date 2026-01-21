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
        SELECT slug, title, status, published_at, created_at, updated_at
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
function load_content(string $type, string $slug): ?array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM content
        WHERE type = :type AND slug = :slug
        LIMIT 1
    ");

    $stmt->execute([
        'type' => $type,
        'slug' => $slug,
    ]);

    $row = $stmt->fetch();
    if (!$row) return null;

    return [
        'id'           => (int) $row['id'],
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
 * Save or update a content item to the database
 *
 * @param string $type
 * @param string $slug
 * @param array  $data
 * @return bool
 */
function save_content(string $type, string $slug, array $data): bool
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

    // Does content already exist?
    $stmt = $pdo->prepare("
        SELECT id FROM content
        WHERE type = :type AND slug = :slug
        LIMIT 1
    ");
    $stmt->execute([
        'type' => $type,
        'slug' => $slug,
    ]);

    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        // UPDATE
        $stmt = $pdo->prepare("
            UPDATE content SET
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
    } else {
        // INSERT
        $stmt = $pdo->prepare("
            INSERT INTO content (
                type,
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
    }

    invalidate_cache($slug, $type);
    save_sitemap();

    return (bool)$result;
}
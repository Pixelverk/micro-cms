<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Visibility & preview
|--------------------------------------------------------------------------
|
| Every read path that serves a page must agree on who may see unpublished
| content. These helpers are the single source of that rule.
|
| Preview has two layers:
|
|   1. can_preview_content()  — is this person allowed to preview at all?
|   2. is_preview_request()   — did they actually ask for it, with a token?
|
| Only (2) relaxes the query filters and disables caching. Merely being signed
| in must never change what a URL returns, or editor traffic would leak into
| the public HTML cache.
|
*/

/**
 * May the current visitor see drafts / scheduled / archived content?
 *
 * Requires an authenticated user with the preview capability.
 */
function can_preview_content(): bool
{
    if (!function_exists('is_logged_in') || !is_logged_in()) {
        return false;
    }

    if (function_exists('admin_can') && !admin_can('content.preview')) {
        return false;
    }

    return true;
}

/**
 * Cookie name holding the per-browser preview token.
 */
function preview_cookie_name(): string
{
    return 'cms_preview';
}

/**
 * The token that turns a normal URL into a preview URL.
 *
 * It lives in its own cookie rather than the session on purpose: the session
 * id is regenerated on login, which used to leave preview links pointing at a
 * token the server no longer had. A cookie survives that untouched.
 */
function preview_token(): string
{
    static $token = null;

    if (is_string($token) && $token !== '') {
        return $token;
    }

    $cookie = $_COOKIE[preview_cookie_name()] ?? null;

    if (is_string($cookie) && preg_match('/^[a-f0-9]{32}$/', $cookie)) {
        return $token = $cookie;
    }

    return $token = bin2hex(random_bytes(16));
}

/**
 * Issue the preview cookie for this browser (called on login).
 */
function preview_token_issue(?string $token = null): string
{
    $token = $token ?? bin2hex(random_bytes(16));

    $_COOKIE[preview_cookie_name()] = $token;

    if (session_status() !== PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        setcookie(preview_cookie_name(), $token, [
            'expires'  => time() + (30 * 86400),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
    }

    return $token;
}

/**
 * Forget the preview cookie (called on logout).
 */
function preview_token_clear(): void
{
    unset($_COOKIE[preview_cookie_name()]);

    if (session_status() !== PHP_SESSION_NONE) {
        setcookie(preview_cookie_name(), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * Is this request an explicit, token-bearing preview?
 */
function is_preview_request(): bool
{
    if (!can_preview_content()) {
        return false;
    }

    $token = $_GET['preview'] ?? null;

    if (!is_string($token) || $token === '') {
        return false;
    }

    $expected = $_COOKIE[preview_cookie_name()] ?? null;

    if (!is_string($expected) || $expected === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

/**
 * Add (or replace) the preview token on a URL.
 */
function preview_url(string $url): string
{
    $token = preview_token();
    $separator = str_contains($url, '?') ? '&' : '?';

    return $url . $separator . 'preview=' . urlencode($token);
}

/**
 * An extra WHERE fragment (starting with " AND ") that hides content the
 * current visitor is not allowed to see.
 *
 * @return array{sql: string, params: array<string, mixed>}
 */
function content_visibility_sql(): array
{
    if (is_preview_request()) {
        return ['sql' => '', 'params' => []];
    }

    return [
        'sql'    => " AND status = 'published' AND published_at IS NOT NULL AND published_at <= :visibility_now",
        'params' => ['visibility_now' => time()],
    ];
}


/*
|--------------------------------------------------------------------------
| Loading
|--------------------------------------------------------------------------
*/

/**
 * Load a content item by its full slug path, honouring URL prefixes and
 * parent/child nesting.
 */
function load_content_by_slug(string $slug, ?string $type = null): ?array
{
    $theme    = theme_config();
    $settings = load_settings();
    $types    = array_keys($theme['content_types'] ?? []);
    $prefixes = $settings['content_prefixes'] ?? [];
    $visible  = content_visibility_sql();

    $pdo = db();

    foreach ($types as $ct) {
        if ($type !== null && $type !== $ct) {
            continue;
        }

        $prefix = $prefixes[$ct] ?? '';

        // Handle content prefixes (only at root level)
        if ($prefix) {
            if (!str_starts_with($slug, $prefix . '/')) {
                continue;
            }
            $relativeSlug = substr($slug, strlen($prefix) + 1);
        } else {
            $relativeSlug = $slug;
        }

        if ($relativeSlug === '') {
            continue;
        }

        // Split path into segments
        $segments = array_values(array_filter(explode('/', $relativeSlug), 'strlen'));

        $parentId = null;
        $row      = null;

        // Walk the hierarchy
        foreach ($segments as $segment) {
            $sql = "
                SELECT *
                FROM content
                WHERE slug = :slug
                  AND type = :type
                  AND parent_id " . ($parentId === null ? 'IS NULL' : '= :parent_id') . "
                  {$visible['sql']}
                LIMIT 1
            ";

            $params = array_merge($visible['params'], [
                'slug' => $segment,
                'type' => $ct,
            ]);

            if ($parentId !== null) {
                $params['parent_id'] = $parentId;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();

            if (!$row) {
                break;
            }

            $parentId = (int) $row['id'];
        }

        if (!$row) {
            continue;
        }

        // Decode JSON columns safely
        $body = json_decode($row['body'] ?? '', true);
        $meta = json_decode($row['meta'] ?? '', true);

        if (!is_array($body)) {
            throw new RuntimeException("Invalid body JSON for {$ct}/{$relativeSlug}");
        }

        if ($meta !== null && !is_array($meta)) {
            throw new RuntimeException("Invalid meta JSON for {$ct}/{$relativeSlug}");
        }

        // taxonomies
        $tax = load_taxonomies_for_content($row['type'], (int) $row['id']);

        // header/footer from content type settings
        $ctConfig = $theme['content_types'][$ct] ?? [];

        // Full public path (prefix + nested segments), used for canonical URLs.
        $path = ($prefix ? $prefix . '/' : '') . $relativeSlug;

        return [
            'id'           => (int) $row['id'],
            'parent_id'    => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'type'         => $ct,
            'slug'         => end($segments),
            'path'         => $path,
            'title'        => $row['title'],
            'status'       => $row['status'],
            'layout'       => $row['layout'] ?? $ctConfig['default_layout'] ?? $settings['default_layout'] ?? $theme['defaults']['layout'],
            'header'       => $row['header'] ?? $ctConfig['default_header'] ?? $settings['default_header'] ?? $theme['defaults']['header'],
            'footer'       => $row['footer'] ?? $ctConfig['default_footer'] ?? $settings['default_footer'] ?? $theme['defaults']['footer'],
            'meta'         => $meta ?? [],
            'components'   => $body ?? [],
            'created_at'   => (int) $row['created_at'],
            'updated_at'   => (int) $row['updated_at'],
            'published_at' => $row['published_at'] ? (int) $row['published_at'] : null,
            'scheduled_at' => $row['scheduled_at'] ? (int) $row['scheduled_at'] : null,
            'categories'   => $tax['category'],
            'tags'         => $tax['tag'],
        ];
    }

    return null;
}

/**
 * List content items of a given type. Anonymous visitors only ever see
 * published, due content; signed-in editors see everything.
 *
 * @param array{status?: string, q?: string, order?: string} $filters
 */
function list_content(string $type, array $filters = []): array
{
    $pdo = db();

    $sql = "
        SELECT id, slug, title, parent_id, status, published_at, created_at, updated_at, scheduled_at, created_by
        FROM content
        WHERE type = :type
    ";

    $params = ['type' => $type];

    $visible = content_visibility_sql();
    $sql .= $visible['sql'];
    $params = array_merge($params, $visible['params']);

    if (!empty($filters['status'])) {
        $sql .= " AND status = :status";
        $params['status'] = $filters['status'];
    }

    if (!empty($filters['q'])) {
        // Match the title or the indexed body text (search_text).
        $sql .= " AND (title LIKE :q OR search_text LIKE :q)";
        $params['q'] = '%' . $filters['q'] . '%';
    }

    $order = strtoupper((string) ($filters['order'] ?? 'ASC'));
    $sql .= $order === 'DESC'
        ? ' ORDER BY title COLLATE NOCASE DESC'
        : ' ORDER BY title COLLATE NOCASE ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

/**
 * Return recent published content with decoded metadata for theme loops.
 */
function list_recent_content(string $type, int $limit = 3): array
{
    $pdo = db();
    $limit = max(1, min($limit, 50));

    $stmt = $pdo->prepare("
        SELECT id, slug, title, status, published_at, meta
        FROM content
        WHERE type = :type
          AND status = 'published'
          AND published_at IS NOT NULL
          AND published_at <= :now
        ORDER BY published_at DESC, id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([
        'type' => $type,
        'now'  => time(),
    ]);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $item['id'] = (int) $item['id'];
        $item['published_at'] = (int) $item['published_at'];
        $item['meta'] = $item['meta'] ? json_decode($item['meta'], true) : [];
        $items[] = $item;
    }

    return $items;
}

/**
 * Load a content item by ID, honouring visibility.
 */
function load_content_by_id(int $id): ?array
{
    $pdo = db();
    $visible = content_visibility_sql();

    $stmt = $pdo->prepare("
        SELECT *
        FROM content
        WHERE id = :id
        {$visible['sql']}
        LIMIT 1
    ");
    $stmt->execute(array_merge(['id' => $id], $visible['params']));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $meta = $row['meta'] ? json_decode($row['meta'], true) : [];
    $body = $row['body'] ? json_decode($row['body'], true) : [];

    $tax = load_taxonomies_for_content($row['type'], (int) $row['id']);

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
        'meta'         => $meta,
        'body'         => $body,
        'components'   => $body,
        'created_by'   => $row['created_by'] !== null ? (int) $row['created_by'] : null,
        'updated_by'   => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
        'published_at' => $row['published_at'] ? (int) $row['published_at'] : null,
        'scheduled_at' => $row['scheduled_at'] ? (int) $row['scheduled_at'] : null,
        'created_at'   => (int) $row['created_at'],
        'updated_at'   => (int) $row['updated_at'],
        'categories'   => $tax['category'],
        'tags'         => $tax['tag'],
    ];
}

/*
|--------------------------------------------------------------------------
| Status model
|--------------------------------------------------------------------------
|
| draft, scheduled, published, archived. `scheduled` is derived from a future
| `scheduled_at`, so the stored status always matches reality.
|
*/

/**
 * The statuses the CMS understands, in workflow order.
 *
 * @return list<string>
 */
function content_statuses(): array
{
    return ['draft', 'scheduled', 'published', 'archived'];
}

/**
 * Human label for a stored status.
 */
function content_status_label(string $status): string
{
    return ucfirst($status);
}

/**
 * Decide the stored status and the published/scheduled timestamps.
 *
 * Used by both admin/content/save.php and save_content() so the two can never
 * disagree about what "published" means.
 *
 * @param array<string, mixed> $data
 * @return array{status: string, published_at: ?int, scheduled_at: ?int}
 */
function resolve_content_status(array $data, ?int $now = null): array
{
    $now = $now ?? time();

    $status = (string) ($data['status'] ?? 'draft');

    if (!in_array($status, content_statuses(), true)) {
        $status = 'draft';
    }

    // Normalise scheduled_at: accept a timestamp, an ISO string, or nothing.
    $scheduledAt = $data['scheduled_at'] ?? null;

    if ($scheduledAt !== null && $scheduledAt !== '') {
        if (is_numeric($scheduledAt)) {
            $scheduledAt = (int) $scheduledAt;
        } elseif (is_string($scheduledAt)) {
            $scheduledAt = strtotime($scheduledAt) ?: null;
        } else {
            $scheduledAt = null;
        }
    } else {
        $scheduledAt = null;
    }

    // Publishing with a future date means "scheduled", not "live".
    if ($status === 'published' && $scheduledAt !== null && $scheduledAt > $now) {
        $status = 'scheduled';
    }

    // Re-running a live item through the editor keeps its original go-live date.
    $publishedAt = $data['published_at'] ?? null;
    $publishedAt = $publishedAt !== null && $publishedAt !== '' ? (int) $publishedAt : null;

    if ($status === 'published') {
        $publishedAt = $publishedAt ?? $now;
        $scheduledAt = null;
    } elseif ($status === 'scheduled') {
        $publishedAt = null;
    } else {
        // draft / archived: not a live publication.
        $publishedAt = null;
        $scheduledAt = null;
    }

    return [
        'status'       => $status,
        'published_at' => $publishedAt,
        'scheduled_at' => $scheduledAt,
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
/**
 * Save or update a content item.
 *
 * @param array<string, mixed> $context ['reason' => string, 'user_id' => ?int]
 */
function save_content(string $type, string $slug, array $data, ?int $id = null, array $context = []): ?int
{
    $pdo = db();
    $now = time();

    $reason = (string) ($context['reason'] ?? 'save');
    $userId = $context['user_id'] ?? (function_exists('current_user_id') ? current_user_id() : null);
    $userId = $userId !== null ? (int) $userId : null;

    // Ensure required structures exist
    $data['meta'] ??= [];
    $data['body'] ??= [];

    // One place decides status / published_at / scheduled_at.
    $resolved = resolve_content_status($data, $now);

    $status      = $resolved['status'];
    $publishedAt = $resolved['published_at'];
    $scheduledAt = $resolved['scheduled_at'];

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
        // Snapshot the outgoing state and write the new one atomically, so a
        // failure can never leave a version without its matching content.
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Remember the outgoing state; it is snapshotted after the write
            // so de-duplication can compare it against the new live row.
            $outgoingRow = function_exists('content_version_current_row')
                ? content_version_current_row((int) $existingId)
                : null;

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
                updated_by    = :updated_by,
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
            'meta'         => content_version_json($data['meta'], '{}'),
            'body'         => content_version_json($data['body'], '[]'),
            'published_at' => $publishedAt,
            'scheduled_at' => $scheduledAt,
            'updated_by'   => $userId,
            'updated_at'   => $now,
        ]);

        $idToReturn = (int)$existingId;

        // Snapshot the state we just replaced. Taken after the UPDATE so a
        // re-save of the same values is recognised as "already the live row"
        // and does not create a duplicate version.
        if ($outgoingRow && function_exists('save_content_version')) {
            save_content_version((int) $existingId, $outgoingRow, [
                'reason'  => $reason,
                'user_id' => $userId,
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

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
                created_by,
                updated_by,
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
                :created_by,
                :updated_by,
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
            'meta'         => content_version_json($data['meta'], '{}'),
            'body'         => content_version_json($data['body'], '[]'),
            'published_at' => $publishedAt,
            'scheduled_at' => $scheduledAt,
            'created_by'   => $userId,
            'updated_by'   => $userId,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $idToReturn = (int)$pdo->lastInsertId();
    }

    // ----------------------------
    // Housekeeping
    // ----------------------------
    if (function_exists('search_index_content')) {
        search_index_content($idToReturn);
    }

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

/**
 * Ids of every descendant of $id within $allItems, recursively.
 *
 * Keeps a page from being nested under itself or one of its own children.
 * Shared by the content editor and the save handler.
 *
 * @param array<int, array<string, mixed>> $allItems
 * @return list<int>
 */
function content_descendant_ids(int $id, array $allItems): array
{
    $descendants = [];

    foreach ($allItems as $item) {
        if (($item['parent_id'] ?? null) === $id) {
            $descendants[] = $item['id'];
            $descendants = array_merge($descendants, content_descendant_ids($item['id'], $allItems));
        }
    }

    return $descendants;
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

    $pdo = db();

    $stmt = $pdo->prepare("SELECT id, name FROM taxonomy WHERE id = ? AND taxonomy_type = ?");
    $stmt->execute([$id, $kind]);
    $term = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$term) {
        redirect_with_toast($kind, 'error', ucfirst($kind) . ' not found.');
    }

    // Relationships first, so no orphans survive the term.
    $pdo->prepare("DELETE FROM taxonomy_term_relationships WHERE taxonomy_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM taxonomy WHERE id = ? AND taxonomy_type = ?")->execute([$id, $kind]);

    log_activity('taxonomy.deleted', 'taxonomy', $id, (string) $term['name'], ['kind' => $kind]);

    redirect_with_toast($kind, 'success', ucfirst($kind) . ' "' . $term['name'] . '" deleted.');
}
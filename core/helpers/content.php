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
    // Trashed content stays hidden, even from a previewer.
    if (is_preview_request()) {
        return ['sql' => ' AND deleted_at IS NULL', 'params' => []];
    }

    return [
        'sql'    => " AND status = 'published' AND published_at IS NOT NULL AND published_at <= :visibility_now AND deleted_at IS NULL",
        'params' => ['visibility_now' => time()],
    ];
}

/**
 * Visibility fragment for the admin: everything except trashed content.
 *
 * The admin must see drafts, scheduled and archived items — the front-end
 * filter above is deliberately not used there. Pass $trashed to ask for the
 * trash view instead.
 *
 * @return array{sql: string, params: array<string, mixed>}
 */
function content_admin_visibility_sql(bool $trashed = false): array
{
    return $trashed
        ? ['sql' => ' AND deleted_at IS NOT NULL', 'params' => []]
        : ['sql' => ' AND deleted_at IS NULL', 'params' => []];
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
    return content_list_rows($type, $filters, content_visibility_sql(), false);
}

/**
 * Admin listing: drafts and archived items are included, trashed are not
 * unless the caller asked for them.
 *
 * @param array{status?: string, q?: string, order?: string, trashed?: bool} $filters
 */
function list_content_admin(string $type, array $filters = []): array
{
    $trashed = !empty($filters['trashed']);

    return content_list_rows($type, $filters, content_admin_visibility_sql($trashed), true);
}

/**
 * Shared content listing query.
 *
 * @param array<string, mixed> $filters
 * @param array{sql: string, params: array<string, mixed>} $visible
 * @return list<array<string, mixed>>
 */
function content_list_rows(string $type, array $filters, array $visible, bool $withDeletedAt): array
{
    $pdo = db();

    $columns = 'id, slug, title, parent_id, status, published_at, created_at, updated_at, scheduled_at, created_by';

    if ($withDeletedAt) {
        $columns .= ', deleted_at';
    }

    $sql    = "SELECT {$columns} FROM content WHERE type = :type";
    $params = ['type' => $type];

    $sql .= $visible['sql'];
    $params = array_merge($params, $visible['params']);

    if (!empty($filters['status'])) {
        $sql .= " AND status = :status";
        $params['status'] = $filters['status'];
    }

    if (!empty($filters['q'])) {
        // Match the title or the indexed body text (search_text).
        $sql .= " AND (title LIKE :q ESCAPE '\\' OR search_text LIKE :q ESCAPE '\\')";
        $params['q'] = '%' . like_escape((string) $filters['q']) . '%';
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
 * A page of published content of one type, with the totals a pager needs.
 *
 * Front-end visibility applies, so drafts and future items never appear. The
 * type must be one the theme declares: unknown types return an empty page
 * rather than leaking rows the theme knows nothing about.
 *
 * Ordering is by publish date then id: a total order, so LIMIT/OFFSET cannot
 * duplicate or skip a row between pages.
 *
 * @param array{status?: string, q?: string} $filters
 * @param string $baseUrl Url of the listing, for the pager and rel=prev/next.
 * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
 */
function list_content_page(string $type, int $page = 1, int $perPage = 10, array $filters = [], string $baseUrl = ''): array
{
    $page    = max(1, $page);
    $perPage = max(1, $perPage);

    $theme = theme_config();
    if (!isset($theme['content_types'][$type])) {
        return pagination_result([], 0, $page, $perPage, $baseUrl);
    }

    $pdo     = db();
    $visible = content_visibility_sql();

    $where  = ' WHERE type = :type' . $visible['sql'];
    $params = array_merge(['type' => $type], $visible['params']);

    if (!empty($filters['status'])) {
        $where .= ' AND status = :status';
        $params['status'] = $filters['status'];
    }

    if (!empty($filters['q'])) {
        $where .= " AND (title LIKE :q ESCAPE '\\' OR search_text LIKE :q ESCAPE '\\')";
        $params['q'] = '%' . like_escape((string) $filters['q']) . '%';
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM content{$where}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $sql = "SELECT id, slug, parent_id, title, type, meta, published_at, created_at, updated_at
            FROM content{$where}
            ORDER BY published_at DESC, id DESC
            LIMIT {$perPage} OFFSET " . pagination_offset($page, $perPage);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($items as &$item) {
        $item['id']           = (int) $item['id'];
        $item['published_at'] = $item['published_at'] !== null ? (int) $item['published_at'] : null;
        $item['meta']         = $item['meta'] ? json_decode((string) $item['meta'], true) : [];
    }
    unset($item);

    return pagination_result($items, $total, $page, $perPage, $baseUrl);
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
          AND deleted_at IS NULL
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
 * Load a content item by ID, honouring front-end visibility.
 */
function load_content_by_id(int $id): ?array
{
    return content_load_by_id_with_visibility($id, content_visibility_sql());
}

/**
 * Load a content item for the admin: unpublished is fine, trashed is not.
 */
function load_content_by_id_admin(int $id): ?array
{
    return content_load_by_id_with_visibility($id, content_admin_visibility_sql());
}

/**
 * Load a content item whatever its state, trashed included.
 *
 * Used by the trash endpoints: purging has to find the row that trashing hid.
 */
function load_content_by_id_any(int $id): ?array
{
    return content_load_by_id_with_visibility($id, ['sql' => '', 'params' => []]);
}

/**
 * @param array{sql: string, params: array<string, mixed>} $visible
 */
function content_load_by_id_with_visibility(int $id, array $visible): ?array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM content
        WHERE id = :id
        {$visible['sql']}
        LIMIT 1
    ");
    $stmt->execute(array_merge(['id' => $id], $visible['params']));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? content_from_row($row) : null;
}

/**
 * Shape a stored row for the rest of the app.
 */
function content_from_row(array $row): array
{
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
        'deleted_at'   => !empty($row['deleted_at']) ? (int) $row['deleted_at'] : null,
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
 * Human label for a stored status, in the admin language.
 */
function content_status_label(string $status): string
{
    return admin_trans('status_' . $status);
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
    $oldRow = null;

    if ($id) {
        $existingId = $id;
    } else {
        $sql = "
            SELECT id
            FROM content
            WHERE type = :type
              AND slug = :slug
              AND parent_id " . ($parentId === null ? "IS NULL" : "= :parent_id") . "
              AND deleted_at IS NULL
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

            // The old slug/parent are needed to keep a redirect from the URL
            // this edit is moving away from.
            $oldRowStmt = $pdo->prepare("SELECT slug, parent_id FROM content WHERE id = :id LIMIT 1");
            $oldRowStmt->execute(['id' => $existingId]);
            $oldRow = $oldRowStmt->fetch() ?: null;

        $stmt = $pdo->prepare("
            UPDATE content SET
                slug          = :slug,
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
            'slug'         => $slug,
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
        // A trashed item keeps its URL. SQLite's UNIQUE(type, parent_id, slug)
        // treats a NULL parent as distinct, so guard top-level slugs by hand.
        $conflict = $pdo->prepare("
            SELECT id FROM content
            WHERE type = :type
              AND slug = :slug
              AND parent_id " . ($parentId === null ? 'IS NULL' : '= :parent_id') . "
              AND deleted_at IS NOT NULL
            LIMIT 1
        ");
        $conflictParams = ['type' => $type, 'slug' => $slug];
        if ($parentId !== null) {
            $conflictParams['parent_id'] = $parentId;
        }
        $conflict->execute($conflictParams);

        if ($conflict->fetchColumn()) {
            throw new RuntimeException('That slug belongs to an item in the trash. Restore it or delete it permanently first.');
        }

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
    // A live path outranks a redirect. Renaming a page back, or creating one on a
    // path an old entry still owns, has to clear that entry: the front end serves
    // a redirect before it routes, so leaving it would hide the page.
    if ($status === 'published' && function_exists('redirect_forget_path') && function_exists('redirect_content_path')) {
        redirect_forget_path(redirect_content_path($type, [
            'id'        => $idToReturn,
            'slug'      => $slug,
            'parent_id' => $parentId,
        ]));
    }

    // A published item that moved keeps its old URL alive with a 301, so a slug
    // or parent change never strands a link. Recorded after the cleanup above, or
    // renaming a page back would look like a loop and be refused.
    $pathChanged = $oldRow !== null && $existingId
        && ((string) $oldRow['slug'] !== $slug || (string) $oldRow['parent_id'] !== (string) $parentId);

    if ($pathChanged && $status === 'published' && function_exists('redirect_record_slug_change')) {
        redirect_record_slug_change(
            $type,
            ['id' => (int) $existingId, 'slug' => (string) $oldRow['slug'], 'parent_id' => $oldRow['parent_id']],
            ['id' => (int) $existingId, 'slug' => $slug, 'parent_id' => $parentId]
        );
    }

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
 * The URL prefix a content type is served under.
 *
 * The Settings value wins, then the type's manifest entry, which is the same
 * order the router, the sitemap and the search index resolve it in.
 */
function content_url_prefix(string $type): string
{
    $settings = load_settings();
    $theme    = theme_config();

    return (string) ($settings['content_prefixes'][$type] ?? $theme['content_types'][$type]['url_prefix'] ?? '');
}

/**
 * Rows of one content type, with just what building a full slug path needs.
 *
 * Trashed rows are excluded; drafts are not, because a published child still
 * lives under its parent's slug.
 *
 * @return list<array{id: int, type: string, slug: string, parent_id: int|null}>
 */
function content_path_rows(string $type): array
{
    $stmt = db()->prepare("
        SELECT id, type, slug, parent_id
        FROM content
        WHERE type = :type
          AND deleted_at IS NULL
        ORDER BY id ASC
    ");
    $stmt->execute(['type' => $type]);

    $rows = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $row['id']        = (int) $row['id'];
        $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        $rows[]           = $row;
    }

    return $rows;
}

/**
 * The public URL of a content row: its type's prefix, then its parents' slugs.
 *
 * The one place that knows how a content URL is put together, so a menu item,
 * an archive listing and a card cannot each build it slightly differently.
 *
 * @param array<string, mixed> $row A content row, or any array with type, slug and parent_id.
 * @param list<array<string, mixed>> $allRows Rows of the same type; loaded when omitted.
 */
function content_url(array $row, array $allRows = []): string
{
    $type   = (string) ($row['type'] ?? '');
    $prefix = content_url_prefix($type);
    $path   = build_full_slug($row, $allRows !== [] ? $allRows : content_path_rows($type));

    return url(($prefix !== '' ? $prefix . '/' : '') . $path);
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

/*
|--------------------------------------------------------------------------
| Trash
|--------------------------------------------------------------------------
|
| Deleting moves content to the trash: it leaves the front end and the admin
| list but keeps its version history until it is restored or purged. Trashing
| a parent cascades to its descendants, so a subtree never ends up with
| half-visible children.
|
*/

/**
 * Every row of a type as id/slug/parent_id, ignoring visibility.
 *
 * @return list<array{id: int, slug: string, parent_id: ?int}>
 */
function content_tree_rows(string $type): array
{
    $stmt = db()->prepare("SELECT id, slug, parent_id FROM content WHERE type = :type");
    $stmt->execute(['type' => $type]);

    $rows = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = [
            'id'        => (int) $row['id'],
            'slug'      => (string) $row['slug'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
        ];
    }

    return $rows;
}

/**
 * The item and every descendant below it.
 *
 * @param list<array{id: int, slug: string, parent_id: ?int}> $rows
 * @return list<int>
 */
function content_subtree_ids(int $id, array $rows): array
{
    return array_merge([$id], content_descendant_ids($id, $rows));
}

/**
 * Move an item and its descendants to the trash.
 */
function trash_content(int $id): bool
{
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT type FROM content WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $type = (string) $stmt->fetchColumn();

    if ($type === '') {
        return false;
    }

    $ids = content_subtree_ids($id, content_tree_rows($type));
    $now = time();

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $update = $pdo->prepare("
        UPDATE content
        SET deleted_at = ?, updated_at = ?
        WHERE id IN ({$placeholders}) AND deleted_at IS NULL
    ");
    $update->execute(array_merge([$now, $now], $ids));

    if ($update->rowCount() < 1) {
        return false;
    }

    invalidate_cache();
    save_sitemap();

    return true;
}

/**
 * Bring an item and its descendants back out of the trash.
 */
function restore_content(int $id): bool
{
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT type FROM content WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $type = (string) $stmt->fetchColumn();

    if ($type === '') {
        return false;
    }

    $ids = content_subtree_ids($id, content_tree_rows($type));
    $now = time();

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $update = $pdo->prepare("
        UPDATE content
        SET deleted_at = NULL, updated_at = ?
        WHERE id IN ({$placeholders}) AND deleted_at IS NOT NULL
    ");
    $update->execute(array_merge([$now], $ids));

    if ($update->rowCount() < 1) {
        return false;
    }

    invalidate_cache();
    save_sitemap();

    return true;
}

/**
 * Delete an item and its descendants for good, with their version history.
 */
function purge_content(int $id): bool
{
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT type FROM content WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $type = (string) $stmt->fetchColumn();

    if ($type === '') {
        return false;
    }

    $ids = content_subtree_ids($id, content_tree_rows($type));

    foreach ($ids as $contentId) {
        if (function_exists('delete_content_versions')) {
            delete_content_versions((int) $contentId);
        }
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Taxonomy links go with the rows (content ids are unique across types).
    $pdo->prepare("DELETE FROM taxonomy_term_relationships WHERE content_id IN ({$placeholders})")->execute($ids);

    $deleted = $pdo->prepare("DELETE FROM content WHERE id IN ({$placeholders})");
    $deleted->execute($ids);

    if ($deleted->rowCount() < 1) {
        return false;
    }

    if (function_exists('search_index_remove')) {
        foreach ($ids as $contentId) {
            search_index_remove((int) $contentId);
        }
    }

    invalidate_cache();
    save_sitemap();

    return true;
}

/**
 * Purge everything that has been in the trash longer than the retention.
 *
 * @return int items purged
 */
function content_purge_trashed(int $days = 30): int
{
    $cutoff = time() - (max(1, $days) * 86400);

    return content_purge_ids('deleted_at IS NOT NULL AND deleted_at < :cutoff', ['cutoff' => $cutoff]);
}

/**
 * How many items are currently in the trash.
 */
function content_trash_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM content WHERE deleted_at IS NOT NULL')->fetchColumn();
}

/**
 * Purge everything in the trash, whatever its age.
 *
 * @return int items purged
 */
function content_empty_trash(): int
{
    return content_purge_ids('deleted_at IS NOT NULL', []);
}

/**
 * Purge the content rows matching a WHERE clause, with their history.
 *
 * @param array<string, mixed> $params
 * @return int items purged
 */
function content_purge_ids(string $where, array $params): int
{
    $stmt = db()->prepare("SELECT id FROM content WHERE {$where}");
    $stmt->execute($params);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $purged = 0;

    foreach ($ids as $id) {
        if (purge_content((int) $id)) {
            $purged++;
        }
    }

    return $purged;
}

/**
 * Opportunistic purge, at most once a day, from the front-end shutdown hook.
 */
function content_maybe_purge_trash(): void
{
    $marker = STORAGE_PATH . '/.trash-purge';

    if (is_file($marker) && (time() - (int) filemtime($marker)) < 86400) {
        return;
    }

    @touch($marker);

    try {
        content_purge_trashed((int) config('trash.retention_days', 30));
    } catch (Throwable $exception) {
        debug_log('trash purge failed: ' . $exception->getMessage());
    }
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

/*
|--------------------------------------------------------------------------
| Pre-publish checklist
|--------------------------------------------------------------------------
|
| Evaluated when content is about to go live. Blocking rules stop the publish
| (the item is kept as a draft instead); the rest are warnings the editor can
| choose to ignore. Drafts are never checked, so saving work is never held up.
|
*/

/**
 * A component's own definition, or [] when it does not exist.
 *
 * @return array<string, mixed>
 */
function content_component_definition(string $name): array
{
    static $cache = [];

    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }

    $path = theme("components/{$name}.php");

    if (!is_file($path)) {
        $path = CORE_PATH . "/components/{$name}.php";
    }

    if (!is_file($path)) {
        return $cache[$name] = [];
    }

    $component = require $path;

    return $cache[$name] = is_array($component) ? $component : [];
}

/**
 * Collect the presentation-image meta a content type declares.
 *
 * The theme lists them under the content type's `images` key (for example a
 * `thumbnail`, or a `gallery` with `multiple => true`). Values are media ids,
 * theme filenames or absolute URLs, exactly like resolve_image_value().
 *
 * A field the form did not submit is left untouched, so partial saves keep it.
 * A `multiple` field submits an array; the empty sentinel row the editor
 * renders means "remove them all" reaches this function as an array of blanks.
 *
 * @param array<string, mixed> $post
 * @param array<string, mixed> $meta
 * @param array<string, array<string, mixed>> $fields
 * @return array<string, mixed>
 */
function content_collect_images(array $post, array $meta, array $fields): array
{
    foreach ($fields as $key => $field) {
        $value = $post['meta_' . $key] ?? null;

        if ($value === null) {
            continue;
        }

        if (!empty($field['multiple'])) {
            $values = array_values(array_filter(array_map(
                static fn($item): string => is_string($item) ? trim($item) : '',
                is_array($value) ? $value : []
            ), static fn(string $item): bool => $item !== ''));

            if ($values === []) {
                unset($meta[$key]);
            } else {
                $meta[$key] = $values;
            }

            continue;
        }

        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            unset($meta[$key]);
        } else {
            $meta[$key] = $value;
        }
    }

    return $meta;
}

/**
 * Evaluate the pre-publish checklist for an item.
 *
 * `meta` and `body` may be decoded arrays or their JSON column strings, so the
 * editor, the save path and the bulk path can each pass what they hold.
 *
 * @param array<string, mixed> $page
 * @return list<array{rule: string, level: string, ok: bool, detail: string}>
 */
function content_publish_checklist(array $page): array
{
    $body = content_checklist_decode($page['body'] ?? []);
    $meta = content_checklist_decode($page['meta'] ?? []);

    $required = [];
    $altText  = [];
    $links    = [];

    content_checklist_walk($body, $required, $altText, $links);

    return [
        [
            'rule'   => 'title',
            'level'  => 'block',
            'ok'     => trim((string) ($page['title'] ?? '')) !== '',
            'detail' => '',
        ],
        [
            'rule'   => 'required',
            'level'  => 'block',
            'ok'     => $required === [],
            'detail' => implode('; ', $required),
        ],
        [
            'rule'   => 'image_alt',
            'level'  => 'warn',
            'ok'     => $altText === [],
            'detail' => implode('; ', $altText),
        ],
        [
            'rule'   => 'links',
            'level'  => 'warn',
            'ok'     => $links === [],
            'detail' => implode('; ', $links),
        ],
        [
            'rule'   => 'description',
            'level'  => 'warn',
            'ok'     => trim((string) ($meta['description'] ?? '')) !== '',
            'detail' => '',
        ],
    ];
}

/**
 * The blocking failures in a checklist, if any.
 *
 * @param list<array{rule: string, level: string, ok: bool, detail: string}> $items
 * @return list<array{rule: string, level: string, ok: bool, detail: string}>
 */
function content_checklist_blockers(array $items): array
{
    return array_values(array_filter(
        $items,
        static fn(array $item): bool => ($item['level'] ?? '') === 'block' && empty($item['ok'])
    ));
}

/**
 * Decode a JSON column, or pass an already-decoded value through.
 *
 * @return array<string, mixed>
 */
function content_checklist_decode(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    return is_array($value) ? $value : [];
}

/**
 * Walk a component tree, collecting checklist details.
 *
 * @param array<int, mixed> $components
 * @param list<string> $required
 * @param list<string> $altText
 * @param list<string> $links
 */
function content_checklist_walk(array $components, array &$required, array &$altText, array &$links): void
{
    foreach ($components as $component) {
        if (!is_array($component)) {
            continue;
        }

        $name  = (string) ($component['type'] ?? '');
        $props = is_array($component['props'] ?? null) ? $component['props'] : [];

        if ($name !== '') {
            $definition = content_component_definition($name);
            $schema     = is_array($definition['schema'] ?? null) ? $definition['schema'] : [];
            $label      = (string) ($definition['label'] ?? $name);

            foreach ($schema as $field => $rules) {
                if (!is_array($rules)) {
                    continue;
                }

                if (($rules['required'] ?? false) && !validate_required($props[$field] ?? '')) {
                    $required[] = $label . ': ' . (string) ($rules['label'] ?? $field);
                }
            }

            // An image the theme gives a description field must describe itself.
            foreach (['image', 'img'] as $imageField) {
                $imageValue = (string) ($props[$imageField] ?? '');

                if ($imageValue === '') {
                    continue;
                }

                $altField = content_checklist_alt_field($schema, $imageField);

                if ($altField === null) {
                    continue;
                }

                if (trim((string) ($props[$altField] ?? '')) !== '') {
                    continue;
                }

                // A media library image already carries its own alt text.
                if (ctype_digit($imageValue) && trim((string) (media_by_id((int) $imageValue)['alt_text'] ?? '')) !== '') {
                    continue;
                }

                $altText[] = $label . ': ' . (string) ($schema[$altField]['label'] ?? $altField);
            }

            // A link label with no target renders a dead link.
            foreach ($schema as $field => $rules) {
                if (!is_array($rules) || !content_checklist_is_link_field($field, $rules)) {
                    continue;
                }

                if (trim((string) ($props[$field] ?? '')) !== '') {
                    continue;
                }

                if (content_checklist_link_text($schema, $props, $field) !== '') {
                    $links[] = $label . ': ' . (string) ($rules['label'] ?? $field);
                }
            }
        }

        $children = is_array($component['children'] ?? null) ? $component['children'] : [];
        content_checklist_walk($children, $required, $altText, $links);
    }
}

/**
 * The alt field a schema pairs with an image field, if any.
 *
 * @param array<string, mixed> $schema
 */
function content_checklist_alt_field(array $schema, string $imageField): ?string
{
    foreach ([$imageField . '_alt', 'alt', 'alt_text'] as $candidate) {
        if (isset($schema[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Is this schema field a link target?
 *
 * @param array<string, mixed> $rules
 */
function content_checklist_is_link_field(string $field, array $rules): bool
{
    if (($rules['type'] ?? '') === 'url') {
        return true;
    }

    return $field === 'url' || $field === 'href' || str_ends_with($field, '_url');
}

/**
 * The visible text paired with a link target, or ''.
 *
 * Only same-prefix pairs count (`btn1_url` with `btn1_text`), plus the
 * `url`/`linktext` pair the theme uses for a call to action. A URL with no
 * label (`redirect_url`) is not a visible link, so it is ignored.
 *
 * @param array<string, mixed> $schema
 * @param array<string, mixed> $props
 */
function content_checklist_link_text(array $schema, array $props, string $field): string
{
    $candidates = [];

    if (str_ends_with($field, '_url')) {
        $prefix = substr($field, 0, -4);
        $candidates[] = $prefix . '_text';
        $candidates[] = $prefix . '_label';
    } elseif ($field === 'url') {
        $candidates[] = 'linktext';
    }

    foreach ($candidates as $candidate) {
        if (isset($schema[$candidate]) && trim((string) ($props[$candidate] ?? '')) !== '') {
            return (string) $props[$candidate];
        }
    }

    return '';
}
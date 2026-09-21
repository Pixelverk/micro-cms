<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content reads and writes
|--------------------------------------------------------------------------
|
| Loading, listing, status, saving and URL/link helpers for the content table.
| Visibility and preview live in core/modules/platform/preview.php; taxonomy
| and the rest of the model move into this module in a later phase.
*/

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


/**
 * The homepage's slug, or '' when none is set or it no longer resolves.
 *
 * Resolved here rather than in load_settings() so the settings module does not
 * depend on content.
 */
function content_homepage_slug(): string
{
    $homepageId = (int) get_setting('homepage_id', 0);

    if ($homepageId <= 0) {
        return '';
    }

    $page = load_content_by_id($homepageId);

    return (string) ($page['slug'] ?? '');
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
            'taxonomies'   => $tax,
            'categories'   => $tax['category'] ?? [],
            'tags'         => $tax['tag'] ?? [],
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
        'taxonomies'   => $tax,
        'categories'   => $tax['category'] ?? [],
        'tags'         => $tax['tag'] ?? [],
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
 * Is a link one we can resolve against this site?
 *
 * A root-relative path, or an absolute URL on the configured host, is ours.
 * `#`, mail and script schemes, protocol-relative URLs and other hosts are not.
 */
function content_link_is_internal(string $href): bool
{
    $href = trim($href);

    if ($href === '' || str_starts_with($href, '#')) {
        return false;
    }

    if (preg_match('#^(mailto:|tel:|javascript:|data:)#i', $href) === 1 || str_starts_with($href, '//')) {
        return false;
    }

    if (preg_match('#^https?://#i', $href) === 1) {
        $host     = parse_url($href, PHP_URL_HOST);
        $siteHost = parse_url(site_origin(), PHP_URL_HOST);

        return is_string($host) && $host !== ''
            && is_string($siteHost) && $siteHost !== ''
            && strcasecmp($host, $siteHost) === 0;
    }

    return str_starts_with($href, '/');
}



/**
 * A link's path, with query, fragment and this install's base path removed.
 */
function content_link_normalize(string $href): string
{
    if (preg_match('#^https?://#i', $href) === 1) {
        $href = (string) (parse_url($href, PHP_URL_PATH) ?? '');
    }

    $href = (string) (preg_split('/[?#]/', $href)[0] ?? $href);
    $href = rawurldecode($href);

    // A subfolder install prefixes every URL with config('url'); a typed link
    // can carry it even though the path itself does not.
    $base = trim((string) config('url', ''), '/');

    if ($base !== '') {
        if ($href === '/' . $base) {
            $href = '/';
        } elseif (str_starts_with($href, '/' . $base . '/')) {
            $href = substr($href, strlen($base) + 1);
        }
    }

    return trim($href, '/');
}



/**
 * Does an internal link resolve on the front end?
 *
 * Mirrors route_request(): the virtual documents, the archive routes, then
 * content by full path through load_content_by_slug(). A redirect is served
 * before routing, so a path that 301s resolves as well, and /media/… is a file.
 */
function content_link_resolves(string $href): bool
{
    if (!content_link_is_internal($href)) {
        return false;
    }

    $path = content_link_normalize($href);

    if (str_contains($path, '..')) {
        return false;
    }

    if ($path === '') {
        $homepageId = (int) (load_settings()['homepage_id'] ?? 0);

        if ($homepageId <= 0) {
            return false;
        }

        $home = load_content_by_id($homepageId);

        // A homepage that is not itself reachable (a draft, say) 404s at /.
        return is_array($home) && content_link_resolves(content_url($home));
    }

    if (in_array($path, ['search', 'sitemap.xml', 'robots.txt', 'site.webmanifest'], true)) {
        return true;
    }

    if (str_starts_with($path, 'media/')) {
        $root = realpath(STORAGE_PATH . '/media');
        $file = realpath(STORAGE_PATH . '/media/' . substr($path, strlen('media/')));

        return $root !== false && $file !== false && str_starts_with($file, $root . '/');
    }

    if (preg_match('#^(category|tag)/([^/]+)$#', $path, $matches) === 1) {
        $stmt = db()->prepare("SELECT 1 FROM taxonomy WHERE taxonomy_type = ? AND slug = ? LIMIT 1");
        $stmt->execute([$matches[1], $matches[2]]);

        return (bool) $stmt->fetchColumn();
    }

    // A redirect is served before routing, so it resolves for a visitor.
    foreach (redirect_all() as $row) {
        if (redirect_normalize_path((string) $row['from_path']) === $path) {
            return true;
        }
    }

    return load_content_by_slug($path) !== null;
}



/**
 * Internal links in published, non-trashed content that no longer resolve.
 *
 * Only what a visitor can reach is checked: a draft's links are the pre-publish
 * checklist's problem. Component props the schema calls a link and `<a href>`
 * inside rich text are both examined, plus link-shaped meta keys such as the
 * portfolio's project_url.
 *
 * @return list<array{id: int, type: string, title: string, field: string, href: string}>
 */
function content_broken_links(): array
{
    $visible = content_visibility_sql();

    $stmt = db()->prepare("SELECT id, type, title, meta, body FROM content WHERE 1 = 1 " . $visible['sql'] . " ORDER BY type, title");
    $stmt->execute($visible['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $findings = [];

    $note = static function (array $row, string $field, string $href) use (&$findings): void {
        $findings[] = [
            'id'    => (int) $row['id'],
            'type'  => (string) $row['type'],
            'title' => (string) $row['title'],
            'field' => $field,
            'href'  => $href,
        ];
    };

    $check = static function (array $row, string $field, string $href) use ($note): void {
        if (content_link_is_internal($href) && !content_link_resolves($href)) {
            $note($row, $field, $href);
        }
    };

    foreach ($rows as $row) {
        $meta = json_decode((string) $row['meta'], true);

        if (is_array($meta)) {
            foreach ($meta as $key => $value) {
                if (!is_scalar($value) || !content_checklist_is_link_field((string) $key, [])) {
                    continue;
                }

                $href = trim((string) $value);

                if ($href !== '') {
                    $check($row, 'meta.' . $key, $href);
                }
            }
        }

        $body = json_decode((string) $row['body'], true);

        if (!is_array($body)) {
            continue;
        }

        $walk = static function (array $components) use (&$walk, $check, $row): void {
            foreach ($components as $component) {
                if (!is_array($component)) {
                    continue;
                }

                $name   = (string) ($component['type'] ?? '');
                $props  = is_array($component['props'] ?? null) ? $component['props'] : [];
                $schema = $name !== '' ? (content_component_definition($name)['schema'] ?? []) : [];

                foreach ($props as $field => $value) {
                    if (!is_scalar($value)) {
                        continue;
                    }

                    $href = trim((string) $value);

                    if ($href === '') {
                        continue;
                    }

                    $rules = is_array($schema[$field] ?? null) ? $schema[$field] : [];

                    if (content_checklist_is_link_field((string) $field, $rules)) {
                        $check($row, (string) $field, $href);
                        continue;
                    }

                    // Rich text stores raw HTML, so its links are read from it.
                    if (($rules['type'] ?? '') === 'quill'
                        && preg_match_all('/<a\b[^>]*\bhref\s*=\s*("|\')(.*?)\1/is', (string) $value, $matches)) {
                        foreach ($matches[2] as $rawHref) {
                            $rawHref = trim(html_entity_decode($rawHref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                            if ($rawHref !== '') {
                                $check($row, (string) $field, $rawHref);
                            }
                        }
                    }
                }

                $walk(is_array($component['children'] ?? null) ? $component['children'] : []);
            }
        };

        $walk($body);
    }

    return $findings;
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

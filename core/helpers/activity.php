<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Activity log (audit trail)
|--------------------------------------------------------------------------
|
| A flat, append-only record of who did what. Deliberately simple:
|
|   action       machine name, e.g. "content.published"
|   object_type  "content", "user", "media", "settings", …
|   object_id    the affected row, when there is one
|   summary      short human sentence
|   meta         extra context as JSON
|
| Logging is best-effort: a failure to write must never break the request
| that triggered it, so every entry point swallows its own exceptions.
|
*/

/**
 * Is the table available? Memoised, because logging happens on hot paths.
 */
function activity_table_exists(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        db()->query("SELECT 1 FROM activity_log LIMIT 1");
        $exists = true;
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

/**
 * Record an event.
 *
 * @param array<string, mixed> $meta Extra context (stored as JSON)
 */
function log_activity(
    string $action,
    ?string $objectType = null,
    ?int $objectId = null,
    string $summary = '',
    array $meta = []
): void {
    if ($action === '' || !activity_table_exists()) {
        return;
    }

    try {
        $user = function_exists('current_user') ? current_user() : null;

        $stmt = db()->prepare("
            INSERT INTO activity_log (user_id, username, action, object_type, object_id, summary, meta, ip, created_at)
            VALUES (:user_id, :username, :action, :object_type, :object_id, :summary, :meta, :ip, :created_at)
        ");

        $stmt->execute([
            'user_id'     => $user ? (int) $user['id'] : null,
            'username'    => $user['username'] ?? null,
            'action'      => $action,
            'object_type' => $objectType,
            'object_id'   => $objectId,
            'summary'     => $summary !== '' ? $summary : null,
            'meta'        => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at'  => time(),
        ]);
    } catch (Throwable $exception) {
        // Auditing must never take the site down.
        debug_log('log_activity failed: ' . $exception->getMessage());
    }
}

/**
 * Every action prefix the UI knows about, for filter dropdowns.
 *
 * @return list<string>
 */
function activity_groups(): array
{
    return ['user', 'content', 'media', 'taxonomy', 'menu', 'form', 'settings', 'security', 'utility'];
}

/**
 * Human label for an action code.
 */
function activity_action_label(string $action): string
{
    static $labels = [
        'content.created'     => 'Created content',
        'content.duplicated'  => 'Duplicated content',
        'content.updated'     => 'Updated content',
        'content.published'   => 'Published content',
        'content.scheduled'   => 'Scheduled content',
        'content.drafted'     => 'Reverted to draft',
        'content.archived'    => 'Archived content',
        'content.deleted'     => 'Deleted content',
        'content.trashed'     => 'Moved to trash',
        'content.untrashed'   => 'Restored from trash',
        'content.purged'      => 'Deleted permanently',
        'content.restored'    => 'Restored a version',
        'media.uploaded'      => 'Uploaded media',
        'form.status'         => 'Changed a submission status',
        'form.deleted'        => 'Deleted form submissions',
        'media.replaced'      => 'Replaced media',
        'media.deleted'       => 'Deleted media',
        'user.created'        => 'Created a user',
        'user.updated'        => 'Updated a user',
        'user.deleted'        => 'Deleted a user',
        'user.login'          => 'Signed in',
        'user.logout'         => 'Signed out',
        'user.login_failed'   => 'Failed sign-in',
        'user.login_locked'   => 'Locked out',
        'settings.updated'    => 'Changed settings',
        'menu.updated'        => 'Updated a menu',
        'menu.deleted'        => 'Deleted a menu',
        'taxonomy.created'    => 'Created a term',
        'taxonomy.updated'    => 'Updated a term',
        'taxonomy.deleted'    => 'Deleted a term',
        'utility.cache_cleared' => 'Cleared the cache',
        'utility.sitemap'     => 'Regenerated the sitemap',
        'utility.published_due' => 'Published due content',
        'utility.migrations'  => 'Ran migrations',
        'security.csrf_failed' => 'Rejected a bad token',
    ];

    if (isset($labels[$action])) {
        return $labels[$action];
    }

    // Fall back to a readable form of the machine name.
    return ucfirst(str_replace(['.', '_'], ' ', $action));
}

/**
 * Query the log.
 *
 * @param array{action?: string, object_type?: string, user_id?: int, search?: string, since?: int, until?: int} $filters
 * @return array{items: list<array<string, mixed>>, total: int}
 */
function list_activity(array $filters = [], int $limit = 50, int $offset = 0): array
{
    if (!activity_table_exists()) {
        return ['items' => [], 'total' => 0];
    }

    $where = [];
    $params = [];

    if (!empty($filters['action'])) {
        // Match a group ("content") or one exact action ("content.updated").
        if (str_contains((string) $filters['action'], '.')) {
            $where[] = 'action = :action';
            $params['action'] = $filters['action'];
        } else {
            $where[] = 'action LIKE :action_prefix';
            $params['action_prefix'] = $filters['action'] . '.%';
        }
    }

    if (!empty($filters['object_type'])) {
        $where[] = 'object_type = :object_type';
        $params['object_type'] = $filters['object_type'];
    }

    if (!empty($filters['user_id'])) {
        $where[] = 'user_id = :user_id';
        $params['user_id'] = (int) $filters['user_id'];
    }

    if (!empty($filters['search'])) {
        $where[] = '(summary LIKE :search OR username LIKE :search OR action LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['since'])) {
        $where[] = 'created_at >= :since';
        $params['since'] = (int) $filters['since'];
    }

    if (!empty($filters['until'])) {
        $where[] = 'created_at <= :until';
        $params['until'] = (int) $filters['until'];
    }

    $sql = 'FROM activity_log';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $count = db()->prepare("SELECT COUNT(*) {$sql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $limit = max(1, min($limit, 200));
    $offset = max(0, $offset);

    $stmt = db()->prepare("SELECT * {$sql} ORDER BY created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);

    return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
}

/**
 * Everything recorded about one object, newest first.
 *
 * @return list<array<string, mixed>>
 */
function list_activity_for_object(string $objectType, int $objectId, int $limit = 20): array
{
    if (!activity_table_exists()) {
        return [];
    }

    $limit = max(1, min($limit, 100));

    $stmt = db()->prepare("
        SELECT *
        FROM activity_log
        WHERE object_type = :type AND object_id = :id
        ORDER BY created_at DESC, id DESC
        LIMIT {$limit}
    ");
    $stmt->execute(['type' => $objectType, 'id' => $objectId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Distinct actors, for the filter dropdown.
 *
 * @return list<array{user_id: ?int, username: ?string}>
 */
function activity_actors(int $limit = 50): array
{
    if (!activity_table_exists()) {
        return [];
    }

    $limit = max(1, min($limit, 200));

    $stmt = db()->query("
        SELECT user_id, username, MAX(created_at) AS last_seen
        FROM activity_log
        WHERE username IS NOT NULL
        GROUP BY username
        ORDER BY last_seen DESC
        LIMIT {$limit}
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Delete entries older than the retention window.
 *
 * @return int rows removed
 */
function prune_activity(int $olderThanDays = 180): int
{
    if (!activity_table_exists()) {
        return 0;
    }

    $cutoff = time() - (max(1, $olderThanDays) * 86400);

    $stmt = db()->prepare("DELETE FROM activity_log WHERE created_at < :cutoff");
    $stmt->execute(['cutoff' => $cutoff]);

    return $stmt->rowCount();
}

/**
 * Opportunistic cleanup: roughly one request in a hundred does the sweep.
 */
function activity_maybe_prune(): void
{
    if (random_int(1, 100) !== 1) {
        return;
    }

    try {
        prune_activity((int) config('activity.retention_days', 180));
    } catch (Throwable $exception) {
        // Ignore: this is housekeeping.
    }
}


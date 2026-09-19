<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content version history
|--------------------------------------------------------------------------
|
| Every meaningful save snapshots the *previous* state of the row, so the
| newest version always describes "what it looked like before this change".
| Restoring loads a snapshot, snapshots the current state first, and writes
| the snapshot back through the normal save path.
|
| Retention is bounded (config `versions.keep`, default 20) so a long-lived
| page cannot grow the table without limit.
|
*/

/**
 * How many versions to keep per content item.
 */
function content_version_keep(): int
{
    return max(1, (int) config('versions.keep', 20));
}

/**
 * The parts of a content row that are versioned.
 *
 * `meta` and `body` are canonicalised to JSON strings. Passing an empty PHP
 * array through json_encode() yields "[]" while the column stores "{}", and
 * comparing those two spellings of "nothing" used to make identical states
 * look different (breaking both de-duplication and diffs).
 *
 * @return array<string, mixed>
 */
function content_version_payload(array $row): array
{
    return [
        'title'        => (string) ($row['title'] ?? ''),
        'status'       => (string) ($row['status'] ?? 'draft'),
        'layout'       => $row['layout'] ?? null,
        'header'       => $row['header'] ?? null,
        'footer'       => $row['footer'] ?? null,
        // meta is an object, body is a list: keep each in its schema shape.
        'meta'         => content_version_json($row['meta'] ?? null, '{}'),
        'body'         => content_version_json($row['body'] ?? null, '[]'),
        'published_at' => ($row['published_at'] ?? null) !== null ? (int) $row['published_at'] : null,
        'scheduled_at' => ($row['scheduled_at'] ?? null) !== null ? (int) $row['scheduled_at'] : null,
    ];
}

/**
 * Canonical JSON string for a JSON column or a decoded value.
 *
 * @param string $emptyAs What an empty collection should be spelled as
 */
function content_version_json(mixed $value, string $emptyAs = '{}'): string
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $value = $decoded;
        } else {
            // Not valid JSON: store it as a JSON string rather than losing it.
            return (string) json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    if ($value === null || (is_array($value) && $value === [])) {
        return $emptyAs;
    }

    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Stable fingerprint of a versioned payload, used to skip no-op saves.
 *
 * Timestamps are deliberately excluded: `published_at` is re-stamped on saves
 * that happen to cross a second boundary, and that must not make identical
 * content look changed (which would create phantom versions).
 */
function content_version_hash(array $payload): string
{
    unset($payload['published_at'], $payload['scheduled_at']);
    ksort($payload);

    return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * The current stored row, or null.
 */
function content_version_current_row(int $contentId): ?array
{
    $stmt = db()->prepare("SELECT * FROM content WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $contentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Snapshot a row into content_versions.
 *
 * Two de-duplication rules keep the history meaningful:
 *
 *  - skip when the payload matches the newest snapshot (repeating a save), and
 *  - unless `$context['live']` is false, skip when the payload matches the row
 *    that is *currently live*, because such a snapshot would only restate what
 *    the content already says.
 *
 * save_content() passes 'live' => false: it snapshots the outgoing state
 * *before* overwriting the row, so at that moment the outgoing state is still
 * the live row and comparing them would suppress all history.
 *
 * @param array<string, mixed> $row     A content row (or the version payload shape)
 * @param array<string, mixed> $context ['reason' => string, 'user_id' => ?int, 'live' => bool]
 * @return int|null The new version id, or null when nothing was written
 */
function save_content_version(int $contentId, array $row, array $context = []): ?int
{
    $payload = content_version_payload($row);
    $hash    = content_version_hash($payload);

    $pdo = db();

    $latest = $pdo->prepare("
        SELECT version, content_hash
        FROM content_versions
        WHERE content_id = :id
        ORDER BY version DESC
        LIMIT 1
    ");
    $latest->execute(['id' => $contentId]);
    $newest = $latest->fetch(PDO::FETCH_ASSOC);

    if ($newest && hash_equals((string) $newest['content_hash'], $hash)) {
        return null;
    }

    // A snapshot equal to the state that is currently live is not history: it
    // would just restate what the content already says. This happens when an
    // edit is reverted (A -> B -> A) while B was never snapshotted.
    if (($context['live'] ?? true) !== false) {
        $live = content_version_current_row($contentId);

        if ($live && hash_equals(content_version_hash(content_version_payload($live)), $hash)) {
            return null;
        }
    }

    $nextVersion = $newest ? ((int) $newest['version'] + 1) : 1;

    $reason = (string) ($context['reason'] ?? 'save');
    $userId = $context['user_id'] ?? (function_exists('current_user_id') ? current_user_id() : null);

    $insert = $pdo->prepare("
        INSERT INTO content_versions (
            content_id, version, title, status, layout, header, footer,
            meta, body, published_at, scheduled_at, reason, content_hash, created_by, created_at
        ) VALUES (
            :content_id, :version, :title, :status, :layout, :header, :footer,
            :meta, :body, :published_at, :scheduled_at, :reason, :content_hash, :created_by, :created_at
        )
    ");

    $insert->execute([
        'content_id'   => $contentId,
        'version'      => $nextVersion,
        'title'        => $payload['title'],
        'status'       => $payload['status'],
        'layout'       => $payload['layout'],
        'header'       => $payload['header'],
        'footer'       => $payload['footer'],
        'meta'         => $payload['meta'],
        'body'         => $payload['body'],
        'published_at' => $payload['published_at'],
        'scheduled_at' => $payload['scheduled_at'],
        'reason'       => $reason,
        'content_hash' => $hash,
        'created_by'   => $userId !== null ? (int) $userId : null,
        'created_at'   => time(),
    ]);

    $versionId = (int) $pdo->lastInsertId();

    prune_content_versions($contentId);

    return $versionId;
}


/**
 * Snapshot whatever is currently stored for a content item.
 * Convenience wrapper used before overwriting a row.
 *
 * Skips the write when the stored state already matches the newest snapshot
 * (see save_content_version), so calling this before every save is safe.
 */
function snapshot_current_content(int $contentId, array $context = []): ?int
{
    $row = content_version_current_row($contentId);

    if (!$row) {
        return null;
    }

    return save_content_version($contentId, $row, $context);
}

/**
 * Version history for an item, newest first.
 *
 * @return list<array<string, mixed>>
 */
function list_content_versions(int $contentId, int $limit = 30): array
{
    $limit = max(1, min($limit, 200));

    $stmt = db()->prepare("
        SELECT v.*, u.username
        FROM content_versions v
        LEFT JOIN users u ON u.id = v.created_by
        WHERE v.content_id = :id
        ORDER BY v.version DESC
        LIMIT {$limit}
    ");
    $stmt->execute(['id' => $contentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function load_content_version(int $versionId): ?array
{
    $stmt = db()->prepare("
        SELECT v.*, u.username
        FROM content_versions v
        LEFT JOIN users u ON u.id = v.created_by
        WHERE v.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $versionId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Number of versions stored for an item.
 */
function count_content_versions(int $contentId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM content_versions WHERE content_id = :id");
    $stmt->execute(['id' => $contentId]);

    return (int) $stmt->fetchColumn();
}

/**
 * The newest autosave snapshot for an item, or null.
 *
 * The editor offers it back when it is newer than the stored row, which means
 * a tab went away with unsaved work.
 */
function latest_content_autosave(int $contentId): ?array
{
    $stmt = db()->prepare("
        SELECT * FROM content_versions
        WHERE content_id = :id AND reason = 'autosave'
        ORDER BY version DESC
        LIMIT 1
    ");
    $stmt->execute(['id' => $contentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Restore a snapshot. The state being replaced is snapshotted first, so a
 * restore is itself undoable.
 *
 * @return bool true when the content row was rewritten
 */
function restore_content_version(int $versionId, array $context = []): bool
{
    $version = load_content_version($versionId);

    if (!$version) {
        return false;
    }

    $contentId = (int) $version['content_id'];
    $row = content_version_current_row($contentId);

    if (!$row) {
        return false;
    }

    $meta = json_decode((string) ($version['meta'] ?? '{}'), true);
    $body = json_decode((string) ($version['body'] ?? '[]'), true);

    return save_content((string) $row['type'], (string) $row['slug'], [
        'title'        => (string) $version['title'],
        'status'       => (string) $version['status'],
        'layout'       => $version['layout'],
        'header'       => $version['header'],
        'footer'       => $version['footer'],
        'meta'         => is_array($meta) ? $meta : [],
        'body'         => is_array($body) ? $body : [],
        'published_at' => $version['published_at'],
        'scheduled_at' => $version['scheduled_at'],
        'parent_id'    => $row['parent_id'],
    ], $contentId, ['reason' => $context['reason'] ?? 'restore'] + $context) !== null;
}

/**
 * Keep only the newest N versions for an item.
 *
 * Autosaves are working drafts rather than history: at most one is kept, and
 * it does not count against the retention window, so a long editing session
 * cannot push real snapshots out of the history.
 *
 * @return int number of rows removed
 */
function prune_content_versions(int $contentId, ?int $keep = null): int
{
    $keep = max(1, $keep ?? content_version_keep());

    $pdo = db();
    $removed = 0;

    $autosaves = $pdo->prepare("
        DELETE FROM content_versions
        WHERE content_id = ?
          AND reason = 'autosave'
          AND id NOT IN (
              SELECT id FROM content_versions
              WHERE content_id = ? AND reason = 'autosave'
              ORDER BY version DESC
              LIMIT 1
          )
    ");
    $autosaves->execute([$contentId, $contentId]);
    $removed += $autosaves->rowCount();

    // $keep is an int, so interpolating it is safe and avoids binding LIMIT.
    $real = $pdo->prepare("
        DELETE FROM content_versions
        WHERE content_id = ?
          AND reason <> 'autosave'
          AND id NOT IN (
              SELECT id FROM content_versions
              WHERE content_id = ? AND reason <> 'autosave'
              ORDER BY version DESC
              LIMIT {$keep}
          )
    ");
    $real->execute([$contentId, $contentId]);
    $removed += $real->rowCount();

    return $removed;
}

/**
 * Remove all history for an item (used when content is deleted).
 */
function delete_content_versions(int $contentId): void
{
    $stmt = db()->prepare("DELETE FROM content_versions WHERE content_id = :id");
    $stmt->execute(['id' => $contentId]);
}

/**
 * Remove one snapshot. Used when an autosave draft is discarded.
 */
function delete_content_version(int $versionId): void
{
    $stmt = db()->prepare("DELETE FROM content_versions WHERE id = :id");
    $stmt->execute(['id' => $versionId]);
}

/**
 * A readable summary of what changed between two payloads.
 *
 * @return list<string>
 */
function content_version_changes(array $old, array $new): array
{
    $changes = [];
    $fields = [
        'title'        => 'Title',
        'status'       => 'Status',
        'layout'       => 'Layout',
        'header'       => 'Header',
        'footer'       => 'Footer',
        'meta'         => 'Metadata',
        'body'         => 'Content',
        'published_at' => 'Publish date',
        'scheduled_at' => 'Schedule',
    ];

    /**
     * Normalise a value so JSON key order and scalar/text differences do not
     * register as a change. Values are canonicalised recursively.
     */
    $normalise = static function (mixed $value) use (&$normalise): mixed {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $normalise($decoded);
            }

            return $value;
        }

        if (is_array($value)) {
            // An explicit empty list means "no components" for body; treat it
            // as equal to an empty map so spelling does not matter.
            if ($value === []) {
                return [];
            }

            ksort($value);

            return array_map($normalise, $value);
        }

        return $value;
    };

    foreach ($fields as $key => $label) {
        $before = $normalise($old[$key] ?? null);
        $after  = $normalise($new[$key] ?? null);

        if ($before === $after) {
            continue;
        }

        $changes[] = $label;
    }

    return $changes;
}

/**
 * Human label for a stored reason code.
 */
function content_version_reason_label(string $reason): string
{
    return match ($reason) {
        'publish'  => 'Published',
        'restore'  => 'Restored',
        'bulk'     => 'Bulk edit',
        'schedule' => 'Scheduled',
        'create'   => 'Created',
        'autosave' => 'Autosaved',
        default    => 'Saved',
    };
}

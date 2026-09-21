<?php
declare(strict_types=1);




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

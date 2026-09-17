<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Scheduled publishing
|--------------------------------------------------------------------------
|
| Content with status "scheduled" and a due `scheduled_at` becomes published.
| Runs automatically after a public request (at most once a minute) and can be
| triggered on demand from the admin utilities page.
|
*/

/**
 * Publish everything that is due. Returns the ids that changed.
 *
 * @return list<int>
 */
function publish_due_content(): array
{
    $pdo = db();
    $now = time();

    // Collect the slugs first so cache invalidation can use them directly.
    $stmt = $pdo->prepare("
        SELECT id, slug, type
        FROM content
        WHERE status = 'scheduled'
          AND scheduled_at IS NOT NULL
          AND scheduled_at <= :now
          AND deleted_at IS NULL
    ");
    $stmt->execute(['now' => $now]);
    $due = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$due) {
        return [];
    }

    $update = $pdo->prepare("
        UPDATE content
        SET status = 'published',
            published_at = :now,
            scheduled_at = NULL,
            updated_at = :now
        WHERE id = :id
          AND status = 'scheduled'
    ");

    $published = [];

    foreach ($due as $item) {
        $update->execute(['id' => (int) $item['id'], 'now' => $now]);

        // Guard against a concurrent request having published it already.
        if ($update->rowCount() > 0) {
            $published[] = (int) $item['id'];
            invalidate_cache($item['slug'], $item['type']);
        }
    }

    if ($published) {
        save_sitemap();
    }

    return $published;
}

/**
 * Cheap automatic pass, run after serving a public page.
 *
 * A marker file keeps a busy site to roughly one check per minute instead of
 * one per request. Failures are logged, never surfaced.
 */
function publishing_check(): void
{
    $marker = STORAGE_PATH . '/.publish-check';

    if (is_file($marker) && (time() - (int) filemtime($marker)) < 60) {
        return;
    }

    @touch($marker);

    try {
        publish_due_content();
    } catch (Throwable $exception) {
        debug_log('publishing_check failed: ' . $exception->getMessage());
    }
}

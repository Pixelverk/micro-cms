<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Duplicate content
|--------------------------------------------------------------------------
| Copies an item into a new draft, so a page structure or post can be reused
| without rebuilding it. The copy gets its own slug and title, carries the
| layout, header, footer, components and taxonomies across, and is always a
| draft: publishing is a separate, deliberate step.
|
| Every database touch sits inside the try below. A write can fail for reasons
| that have nothing to do with this feature (a storage directory the web user
| cannot write), and an escaping PDOException would be a blank 500 rather than
| a message an editor can act on.
*/

// ----------------------------
// POST only
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(admin_trans('error_method'));
}

$contentTypes = theme_config()['content_types'] ?? [];

$id   = (int) ($_POST['id'] ?? 0);
$type = (string) ($_POST['type'] ?? 'page');

if ($id < 1) {
    redirect_with_toast('content', 'error', admin_trans('content_error_missing_id'));
}

if (!isset($contentTypes[$type])) {
    redirect_with_toast('content', 'error', admin_trans('content_error_type'));
}

require_capability('content.create');

/**
 * A sibling-taking slug: first `{slug}-copy`, then `{slug}-copy-2`, and so on.
 *
 * The unique index is (type, parent_id, slug) and applies to trashed rows too,
 * so the check must not filter on deleted_at; a "free" slug that a trashed item
 * still occupies would fail on insert.
 */
$copySlug = function (string $type, ?int $parentId, string $slug): string {
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM content
         WHERE type = :type
           AND slug = :slug
           AND parent_id " . ($parentId === null ? "IS NULL" : "= :parent_id")
    );

    $taken = function (string $candidate) use ($stmt, $type, $parentId): bool {
        $params = ['type' => $type, 'slug' => $candidate];
        if ($parentId !== null) {
            $params['parent_id'] = $parentId;
        }
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    };

    $candidate = $slug . '-copy';

    for ($suffix = 2; $taken($candidate); $suffix++) {
        $candidate = $slug . '-copy-' . $suffix;
    }

    return $candidate;
};

/**
 * The same rule for the title: `{title} (Copy)`, then `(Copy 2)`, and so on.
 */
$copyTitle = function (string $title): string {
    $stmt = db()->prepare("SELECT COUNT(*) FROM content WHERE title = :title");
    $candidate = $title . ' (Copy)';

    for ($suffix = 2; ; $suffix++) {
        $stmt->execute(['title' => $candidate]);

        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }

        $candidate = $title . ' (Copy ' . $suffix . ')';
    }
};

$parentId = null;

/**
 * A translation that cannot itself fail.
 *
 * admin_trans() reaches admin_locale() and get_setting(), and loading settings
 * also loads the homepage row. In a database that is already erroring that
 * lookup throws too, which would turn the handler for one failure into a
 * second, uncaught one. A plain English string is a poor message; a blank 500
 * is worse.
 */
$message = function (string $key, array $replace = []): string {
    try {
        return admin_trans($key, $replace);
    } catch (Throwable $ignored) {
        return 'The content could not be duplicated.';
    }
};

try {
    // The source row is read inside the guard: loading it also loads its
    // taxonomies, and a database problem there must not become a blank 500.
    $source = load_content_by_id_admin($id);

    if (!$source || (string) $source['type'] !== $type) {
        redirect_with_toast('content', 'error', admin_trans('content_error_not_found', ['type' => ucfirst($type)]), ['type' => $type]);
    }

    // You may only copy what you could edit, or an author could reproduce
    // someone else's unpublished work into their own draft.
    if (!can_edit_content($source)) {
        log_activity('security.forbidden', 'content', $id, 'content.duplicate', []);
        http_response_code(403);
        render_admin_forbidden('content.edit.own');
        exit;
    }

    $parentId = $source['parent_id'] !== null ? (int) $source['parent_id'] : null;

    // Copy the metadata an editor would want to start from, but never the
    // canonical: on a copy it points search engines at the original.
    $meta = is_array($source['meta'] ?? null) ? $source['meta'] : [];
    unset($meta['canonical']);

    $slug  = $copySlug($type, $parentId, (string) $source['slug']);
    $title = $copyTitle((string) $source['title']);

    $newId = save_content($type, $slug, [
        'type'         => $type,
        'parent_id'    => $parentId,
        'title'        => $title,
        'status'       => 'draft',
        'layout'       => $source['layout'] ?? null,
        'header'       => $source['header'] ?? null,
        'footer'       => $source['footer'] ?? null,
        'meta'         => $meta,
        'body'         => is_array($source['body'] ?? null) ? $source['body'] : [],
        'published_at' => null,
        'scheduled_at' => null,
    ], null, ['reason' => 'duplicate']);

    if (!$newId) {
        throw new RuntimeException($message('content_duplicate_error'));
    }

    // Copy taxonomy relationships alongside the item.
    db()->prepare("
        INSERT OR IGNORE INTO taxonomy_term_relationships (content_type, content_id, taxonomy_id)
        SELECT content_type, ?, taxonomy_id
        FROM taxonomy_term_relationships
        WHERE content_type = ? AND content_id = ?
    ")->execute([$newId, $type, $id]);

    log_activity('content.duplicated', 'content', (int) $newId, (string) $title, [
        'type'   => $type,
        'source' => $id,
    ]);
} catch (PDOException $exception) {
    // Most likely UNIQUE(type, parent_id, slug), but a read-only database
    // lands here too, and either way the editor needs a message not a 500.
    debug_log('content.duplicate failed: ' . $exception->getMessage());

    $error = str_contains($exception->getMessage(), 'UNIQUE')
        ? $message('content_duplicate_error')
        : $message('content_error_save', ['type' => ucfirst($type)]);

    redirect_with_toast('content', 'error', $error, ['type' => $type]);
} catch (RuntimeException $exception) {
    redirect_with_toast('content', 'error', $exception->getMessage(), ['type' => $type]);
}

redirect_with_toast(
    'content/edit',
    'success',
    $message('content_duplicated', ['title' => (string) $title]),
    [
        'id'   => $newId,
        'type' => $type,
    ]
);

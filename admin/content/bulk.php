<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bulk content actions
|--------------------------------------------------------------------------
|
| One POST endpoint for the content list's bulk toolbar. Every action runs in
| a single transaction, is capability- and ownership-checked per item, and
| reports a summary: how many changed, how many were skipped and why.
|
| Nothing here trusts the posted ids beyond looking them up: each row is
| re-read and re-checked before it is touched.
|
*/

require_capability('content.bulk');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

const BULK_LIMIT = 200;

$action = (string) ($_POST['bulk_action'] ?? '');
$type = (string) ($_POST['type'] ?? 'page');
$rawIds = $_POST['ids'] ?? [];

$theme = theme_config();
$contentTypes = $theme['content_types'] ?? [];

// ----------------------------
// Validate the request
// ----------------------------
$errors = [];

if (!isset($contentTypes[$type])) {
    $errors[] = 'Unknown content type.';
}

$allowedActions = ['publish', 'draft', 'archive', 'delete', 'clear_cache', 'add_tag', 'remove_tag'];

if (!in_array($action, $allowedActions, true)) {
    $errors[] = 'Unknown bulk action.';
}

if (!is_array($rawIds)) {
    $rawIds = [$rawIds];
}

$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn($id) => $id > 0)));

if (!$ids) {
    $errors[] = 'Select at least one item.';
} elseif (count($ids) > BULK_LIMIT) {
    $errors[] = 'Select at most ' . BULK_LIMIT . ' items at a time.';
}

// Publishing needs the publish capability; deletion needs delete.
if ($action === 'publish' && !admin_can('content.publish')) {
    $errors[] = 'Your role cannot publish content.';
}

if ($action === 'delete' && !admin_can('content.delete')) {
    $errors[] = 'Your role cannot delete content.';
}

if ($action === 'add_tag' || $action === 'remove_tag') {
    $tagId = (int) ($_POST['tag_id'] ?? 0);

    if ($tagId <= 0) {
        $errors[] = 'Choose a tag.';
    } else {
        $tagCheck = db()->prepare("SELECT id FROM taxonomy WHERE id = :id AND taxonomy_type = 'tag' LIMIT 1");
        $tagCheck->execute(['id' => $tagId]);

        if (!$tagCheck->fetchColumn()) {
            $errors[] = 'That tag no longer exists.';
        }
    }
}

if ($errors) {
    redirect_with_toast('content', 'error', implode(' ', $errors), ['type' => $type]);
}

// ----------------------------
// Load and filter the selection
// ----------------------------
$pdo = db();

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$load = $pdo->prepare("SELECT * FROM content WHERE type = ? AND id IN ({$placeholders})");
$load->execute(array_merge([$type], $ids));
$rows = $load->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selected = [];
$skipped = 0;

foreach ($rows as $row) {
    $page = [
        'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
        'title'      => (string) $row['title'],
    ];

    // Authors may only touch their own items; anything else is skipped, not
    // silently modified.
    if (!can_edit_content($page)) {
        $skipped++;
        continue;
    }

    $selected[] = $row;
}

// Ids that did not come back belong to another type or no longer exist.
$skipped += count($ids) - count($rows);

if (!$selected) {
    redirect_with_toast('content', 'error', 'None of the selected items could be changed.', ['type' => $type]);
}

// ----------------------------
// Apply
// ----------------------------
$changed = 0;
$now = time();

$pdo->beginTransaction();

try {
    foreach ($selected as $row) {
        $id = (int) $row['id'];

        // Capture the outgoing state before changing it, so a bulk edit can be
        // undone from the history page. Previously this ran after the UPDATE
        // and therefore stored the *new* state instead of the old one.
        $snapshot = null;

        if (in_array($action, ['publish', 'draft', 'archive'], true) && function_exists('content_version_current_row')) {
            $snapshot = content_version_current_row($id);
        }

        switch ($action) {
            case 'publish':
                $resolved = resolve_content_status(['status' => 'published'], $now);

                $update = $pdo->prepare("
                    UPDATE content
                    SET status = :status, published_at = :published_at, scheduled_at = NULL, updated_by = :user, updated_at = :now
                    WHERE id = :id
                ");
                $update->execute([
                    'status'       => $resolved['status'],
                    'published_at' => $resolved['published_at'],
                    'user'         => current_user_id(),
                    'now'          => $now,
                    'id'           => $id,
                ]);
                break;

            case 'draft':
            case 'archive':
                $status = $action === 'draft' ? 'draft' : 'archived';

                $update = $pdo->prepare("
                    UPDATE content
                    SET status = :status, published_at = NULL, scheduled_at = NULL, updated_by = :user, updated_at = :now
                    WHERE id = :id
                ");
                $update->execute([
                    'status' => $status,
                    'user'   => current_user_id(),
                    'now'    => $now,
                    'id'     => $id,
                ]);
                break;

            case 'delete':
                delete_content_versions($id);
                $pdo->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
                break;

            case 'clear_cache':
                invalidate_cache((string) $row['slug'], (string) $row['type']);
                break;

            case 'add_tag':
            case 'remove_tag':
                $tagId = (int) $_POST['tag_id'];

                if ($action === 'add_tag') {
                    $pdo->prepare("
                        INSERT OR IGNORE INTO taxonomy_term_relationships (content_type, content_id, taxonomy_id)
                        VALUES (:type, :id, :tag)
                    ")->execute(['type' => $type, 'id' => $id, 'tag' => $tagId]);
                } else {
                    $pdo->prepare("
                        DELETE FROM taxonomy_term_relationships
                        WHERE content_type = :type AND content_id = :id AND taxonomy_id = :tag
                    ")->execute(['type' => $type, 'id' => $id, 'tag' => $tagId]);
                }
                break;
        }

        if ($snapshot && function_exists('save_content_version')) {
            save_content_version($id, $snapshot, [
                'reason'  => 'bulk',
                'user_id' => current_user_id(),
            ]);
        }

        $changed++;
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    debug_log('bulk action failed: ' . $exception->getMessage());

    redirect_with_toast('content', 'error', 'The bulk action failed and nothing was changed.', ['type' => $type]);
}

// Cache/sitemap upkeep once, not per row.
if (in_array($action, ['publish', 'draft', 'archive', 'delete'], true)) {
    invalidate_cache();
    save_sitemap();
}

log_activity('content.bulk_' . $action, 'content', null, $changed . ' item(s)', [
    'type'    => $type,
    'action'  => $action,
    'ids'     => array_column($selected, 'id'),
    'skipped' => $skipped,
]);

$summary = $changed . ' item(s) updated.';

if ($skipped > 0) {
    $summary .= ' ' . $skipped . ' skipped (not yours to change).';
}

redirect_with_toast('content', 'success', $summary, ['type' => $type]);

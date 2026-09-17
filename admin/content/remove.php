<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Remove content
|--------------------------------------------------------------------------
| Deleting is a move to the trash; posting purge=1 deletes for good. Trashing
| keeps the version history, so an accidental delete is recoverable.
|--------------------------------------------------------------------------
*/

// ----------------------------
// POST only (destructive action)
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$theme        = theme_config();
$contentTypes = $theme['content_types'] ?? [];

$id   = (int) ($_POST['id'] ?? 0);
$type = (string) ($_POST['type'] ?? 'page');
$purge = !empty($_POST['purge']);

if ($id < 1) {
    redirect_with_toast('content', 'error', 'Missing content ID.');
}

if (!isset($contentTypes[$type])) {
    redirect_with_toast('content', 'error', 'Invalid content type.');
}

require_capability('content.delete');

// Any visibility: trashing needs a live row, purging a trashed one.
$existing = load_content_by_id_any($id);

if (!$existing || (string) $existing['type'] !== $type) {
    redirect_with_toast('content', 'error', ucfirst($type) . ' not found.', ['type' => $type]);
}

if ($purge) {
    $done    = purge_content($id);
    $action  = 'content.purged';
    $message = $done ? ucfirst($type) . ' deleted permanently.' : ucfirst($type) . ' could not be deleted.';
} else {
    $done    = trash_content($id);
    $action  = 'content.trashed';
    $message = $done ? ucfirst($type) . ' moved to the trash.' : ucfirst($type) . ' is already in the trash.';
}

if ($done) {
    log_activity($action, 'content', $id, (string) $existing['slug'], ['type' => $type]);
}

redirect_with_toast(
    'content',
    $done ? 'success' : 'error',
    $message,
    $purge ? ['type' => $type, 'status' => 'trash'] : ['type' => $type]
);

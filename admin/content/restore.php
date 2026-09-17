<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Restore content
|--------------------------------------------------------------------------
| Takes an item out of the trash. Trashing cascades to descendants, so this
| brings the whole subtree back.
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(admin_trans('error_method'));
}

$theme        = theme_config();
$contentTypes = $theme['content_types'] ?? [];

$id   = (int) ($_POST['id'] ?? 0);
$type = (string) ($_POST['type'] ?? 'page');

if ($id < 1) {
    redirect_with_toast('content', 'error', admin_trans('content_error_missing_id'));
}

if (!isset($contentTypes[$type])) {
    redirect_with_toast('content', 'error', admin_trans('content_error_type'));
}

require_capability('content.delete');

$restored = restore_content($id);

if ($restored) {
    log_activity('content.untrashed', 'content', $id, '', ['type' => $type]);
}

redirect_with_toast(
    'content',
    $restored ? 'success' : 'error',
    $restored
        ? admin_trans('content_restored', ['type' => ucfirst($type)])
        : admin_trans('trash_not_in_trash'),
    ['type' => $type, 'status' => 'trash']
);

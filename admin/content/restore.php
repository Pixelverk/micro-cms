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
    exit('Method not allowed');
}

$theme        = theme_config();
$contentTypes = $theme['content_types'] ?? [];

$id   = (int) ($_POST['id'] ?? 0);
$type = (string) ($_POST['type'] ?? 'page');

if ($id < 1) {
    redirect_with_toast('content', 'error', 'Missing content ID.');
}

if (!isset($contentTypes[$type])) {
    redirect_with_toast('content', 'error', 'Invalid content type.');
}

require_capability('content.delete');

$restored = restore_content($id);

if ($restored) {
    log_activity('content.untrashed', 'content', $id, '', ['type' => $type]);
}

redirect_with_toast(
    'content',
    $restored ? 'success' : 'error',
    $restored ? ucfirst($type) . ' restored.' : 'That item is not in the trash.',
    ['type' => $type, 'status' => 'trash']
);

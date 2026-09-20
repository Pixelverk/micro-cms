<?php
declare(strict_types=1);

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(admin_trans('error_method'));
}

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    redirect_with_toast('media', 'error', admin_trans('media_error_invalid_item'));
}

$pdo = db();

// Read the name first: media_delete() removes the row, and the log entry should
// say which file went.
$stmt = $pdo->prepare("SELECT original_name FROM media WHERE id = ?");
$stmt->execute([$id]);
$originalName = $stmt->fetchColumn();

$status = media_delete($id);

if ($status === 'not_found') {
    redirect_with_toast('media', 'error', admin_trans('media_error_not_found'));
}

if ($status === 'invalid_path') {
    redirect_with_toast('media', 'error', admin_trans('media_error_invalid_path'));
}

// ----------------------------
// Done
// ----------------------------
log_activity('media.deleted', 'media', $id, (string) ($originalName ?: ''), []);

redirect_with_toast('media', 'success', admin_trans('media_deleted'));
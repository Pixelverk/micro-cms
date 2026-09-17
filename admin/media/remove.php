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

// ----------------------------
// Fetch record
// ----------------------------
$stmt = $pdo->prepare("SELECT base_path FROM media WHERE id = ?");
$stmt->execute([$id]);
$media = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$media) {
    redirect_with_toast('media', 'error', admin_trans('media_error_not_found'));
}

$mediaRoot = realpath(STORAGE_PATH . '/media');
$folder    = realpath($mediaRoot . '/' . $media['base_path']);

if (!$folder || !str_starts_with($folder, $mediaRoot)) {
    redirect_with_toast('media', 'error', admin_trans('media_error_invalid_path'));
}

// ----------------------------
// Delete media folder
// ----------------------------
delete_media_directory($folder);

// ----------------------------
// Cleanup empty parent folders (YYYY/MM)
// ----------------------------
$dir = dirname($folder);
while ($dir !== $mediaRoot && is_dir($dir) && count(scandir($dir)) === 2) {
    @rmdir($dir);
    $dir = dirname($dir);
}

// ----------------------------
// Delete DB record
// ----------------------------
$stmt = $pdo->prepare("DELETE FROM media WHERE id = ?");
$stmt->execute([$id]);

// ----------------------------
// Done
// ----------------------------
log_activity('media.deleted', 'media', (int) $id, (string) ($media['original_name'] ?? ''), []);

redirect_with_toast('media', 'success', admin_trans('media_deleted'));
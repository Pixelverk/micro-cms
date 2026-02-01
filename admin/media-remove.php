<?php
declare(strict_types=1);

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    redirect_with_toast('media', 'error', 'Invalid media item.');
}

$pdo = db();

// ----------------------------
// Fetch record
// ----------------------------
$stmt = $pdo->prepare("SELECT base_path FROM media WHERE id = ?");
$stmt->execute([$id]);
$media = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$media) {
    redirect_with_toast('media', 'error', 'Media not found.');
}

$mediaRoot = realpath(STORAGE_PATH . '/media');
$folder    = realpath($mediaRoot . '/' . $media['base_path']);

if (!$folder || !str_starts_with($folder, $mediaRoot)) {
    redirect_with_toast('media', 'error', 'Invalid media path.');
}

// ----------------------------
// Recursive delete helper
// ----------------------------
function deleteDirRecursive(string $dir): void {
    if (!is_dir($dir)) return;

    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;

        $path = $dir . '/' . $file;

        if (is_dir($path)) {
            deleteDirRecursive($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

// ----------------------------
// Delete media folder
// ----------------------------
deleteDirRecursive($folder);

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
redirect_with_toast('media', 'success', 'Media deleted.');
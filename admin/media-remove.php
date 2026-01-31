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
// Find media record
// ----------------------------
$stmt = $pdo->prepare("SELECT path FROM media WHERE id = ?");
$stmt->execute([$id]);

$media = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$media) {
    redirect_with_toast('media', 'error', 'Media not found.');
}

$mediaRoot = realpath(STORAGE_PATH . '/media');
$filePath  = $mediaRoot . '/' . $media['path'];

// ----------------------------
// Delete file (if exists)
// ----------------------------
if (is_file($filePath)) {
    unlink($filePath);

    // cleanup empty folders (same logic you had)
    $dir = dirname($filePath);
    while ($dir !== $mediaRoot && is_dir($dir) && count(scandir($dir)) === 2) {
        rmdir($dir);
        $dir = dirname($dir);
    }
}

// ----------------------------
// Delete DB record
// ----------------------------
$stmt = $pdo->prepare("DELETE FROM media WHERE id = ?");
$stmt->execute([$id]);

// ----------------------------
// Done
// ----------------------------
redirect_with_toast('media', 'success', 'File deleted.');
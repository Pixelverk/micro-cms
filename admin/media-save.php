<?php
declare(strict_types=1);

// ----------------------------
// Only allow POST
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$pdo = db();
$now = time();

// ----------------------------
// Get POST data
// ----------------------------
$replaceId    = !empty($_POST['replace_id']) ? (int)$_POST['replace_id'] : null;
$altText      = trim($_POST['alt_text'] ?? '');
$description  = trim($_POST['description'] ?? '');
$file         = $_FILES['file'] ?? null;

// ----------------------------
// Config
// ----------------------------
$maxSize = 10 * 1024 * 1024; // 10MB
$allowedExtensions = [
    'jpg','jpeg','png','gif','webp',
    'svg','pdf','mp4','webm'
];

// ----------------------------
// Helper: sanitize filename
// ----------------------------
function sanitizeFilename(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '-', strtolower($name));
}

// ----------------------------
// Friendly PHP upload errors
// ----------------------------
$uploadErrors = [
    UPLOAD_ERR_OK         => null,
    UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the server limit.',
    UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the form limit.',
    UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
    UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
];

// ----------------------------
// Handle file upload if provided
// ----------------------------
$relativePath = $filename = $originalName = $mimeType = $size = null;

if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = $uploadErrors[$file['error']] ?? 'Unknown upload error.';
        redirect_with_toast('media', 'error', 'Upload error: ' . $msg);
    }

    if ($file['size'] > $maxSize) {
        redirect_with_toast('media', 'error', 'File too large.');
    }

    $originalName = $file['name'];
    $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        redirect_with_toast('media', 'error', 'Invalid file type.');
    }

    // ----------------------------
    // Build folder (YYYY/MM)
    // ----------------------------
    $year  = date('Y');
    $month = date('m');

    $targetDir = STORAGE_PATH . "/media/{$year}/{$month}";
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

    // ----------------------------
    // Sanitize filename
    // ----------------------------
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $baseName = sanitizeFilename($baseName);

    $filename   = $baseName . '.' . $extension;
    $targetPath = $targetDir . '/' . $filename;

    // Prevent overwrite
    $counter = 1;
    while (file_exists($targetPath)) {
        $filename   = $baseName . '-' . $counter++ . '.' . $extension;
        $targetPath = $targetDir . '/' . $filename;
    }

    // ----------------------------
    // Move upload
    // ----------------------------
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        redirect_with_toast('media', 'error', 'Failed to move uploaded file.');
    }

    $relativePath = "{$year}/{$month}/{$filename}";
    $mimeType     = mime_content_type($targetPath);
    $size         = filesize($targetPath);
}

// ----------------------------
// Save or update DB record
// ----------------------------
if ($replaceId) {
    // ----------------------------
    // Fetch existing record
    // ----------------------------
    $stmt = $pdo->prepare("SELECT * FROM media WHERE id = ?");
    $stmt->execute([$replaceId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        redirect_with_toast('media', 'error', 'Media item not found.');
    }

    // Delete old file if replacing
    if ($relativePath && !empty($existing['path'])) {
        $oldPath = realpath(STORAGE_PATH . '/media/' . $existing['path']);
        if ($oldPath && is_file($oldPath)) unlink($oldPath);
    }

    // ----------------------------
    // Update record
    // ----------------------------
    $stmt = $pdo->prepare("
        UPDATE media SET
            filename      = COALESCE(?, filename),
            original_name = COALESCE(?, original_name),
            path          = COALESCE(?, path),
            mime_type     = COALESCE(?, mime_type),
            size          = COALESCE(?, size),
            alt_text      = ?,
            description   = ?,
            updated_at    = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $filename ?? null,
        $originalName ?? null,
        $relativePath ?? null,
        $mimeType ?? null,
        $size ?? null,
        $altText,
        $description,
        $now,
        $replaceId
    ]);

    $msg = 'Media updated successfully.';

} else {
    // ----------------------------
    // Insert new record
    // ----------------------------
    if (!$relativePath) {
        redirect_with_toast('media', 'error', 'No file uploaded.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO media (
            filename,
            original_name,
            path,
            mime_type,
            size,
            alt_text,
            description,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $filename,
        $originalName,
        $relativePath,
        $mimeType,
        $size,
        $altText,
        $description,
        $now,
        $now
    ]);

    $msg = 'File uploaded successfully.';
}

// ----------------------------
// Done
// ----------------------------
redirect_with_toast('media', 'success', $msg);
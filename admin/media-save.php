<?php
declare(strict_types=1);

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (empty($_FILES['file'])) {
    http_response_code(400);
    exit('No file uploaded');
}

$file = $_FILES['file'];

// Upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('Upload error: ' . $file['error']);
}

// ----------------------------
// Config
// ----------------------------
$maxSize = 5 * 1024 * 1024; // 5MB

$allowedExtensions = [
    'jpg','jpeg','png','gif','webp',
    'svg',
    'pdf',
    'mp4','webm'
];

// ----------------------------
// Validate
// ----------------------------
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
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

// ----------------------------
// Sanitize filename
// ----------------------------
$baseName = pathinfo($originalName, PATHINFO_FILENAME);
$baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '-', strtolower($baseName));

$filename   = $baseName . '.' . $extension;
$targetPath = $targetDir . '/' . $filename;

// prevent overwrite
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

// ----------------------------
// Save DB record
// ----------------------------
$relativePath = "{$year}/{$month}/{$filename}";
$mimeType     = mime_content_type($targetPath);
$size         = filesize($targetPath);
$now          = time();

$pdo = db();

$stmt = $pdo->prepare("
    INSERT INTO media (
        filename,
        original_name,
        path,
        mime_type,
        size,
        created_at,
        updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?)
");

$stmt->execute([
    $filename,
    $originalName,
    $relativePath,
    $mimeType,
    $size,
    $now,
    $now
]);

// ----------------------------
// Done
// ----------------------------
redirect_with_toast('media', 'success', 'File uploaded.');
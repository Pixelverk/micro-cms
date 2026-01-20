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

// Config
$maxSize   = 5 * 1024 * 1024; // 5MB
$allowedExtensions = [
    'jpg', 'jpeg', 'png', 'gif', 'webp',
    'svg',
    'pdf',
    'mp4', 'webm'
];

// Size check
if ($file['size'] > $maxSize) {
    http_response_code(400);
    redirect_with_toast('media', 'error', 'File too large.');
}

// Filename sanitization
$originalName = $file['name'];
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($extension, $allowedExtensions, true)) {
    http_response_code(400);
    redirect_with_toast('media', 'error', 'Invalid file type.');
}

$year = date('Y');
$month = date('m');

$targetDir = STORAGE_PATH . "/media/{$year}/{$month}";
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

// Prevent overwrites
$baseName = pathinfo($originalName, PATHINFO_FILENAME);
$baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '-', strtolower($baseName));

$filename = $baseName . '.' . $extension;
$targetPath = $targetDir . '/' . $filename;

$counter = 1;
while (file_exists($targetPath)) {
    $filename = $baseName . '-' . $counter++ . '.' . $extension;
    $targetPath = $targetDir . '/' . $filename;
}

// Move upload
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    redirect_with_toast('media', 'error', 'Failed to move uploaded file.');
}

// Build public URL (via symlink)
$publicPath = ($subdir ? $subdir . '/' : '') . $filename;

redirect_with_toast('media', 'success', 'File uploaded.');
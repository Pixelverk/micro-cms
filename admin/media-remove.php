<?php

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Get file path from POST
$path = $_POST['path'] ?? '';

if ($path === '') {
    http_response_code(400);
    redirect_with_toast('media', 'error', 'Missing file path.');
}

// Normalize and sanitize
$path = ltrim($path, '/');
$path = str_replace(['../', '..\\'], '', $path);

$mediaRoot = realpath(STORAGE_PATH . '/media');
$filePath  = realpath($mediaRoot . '/' . $path);

// Security: ensure file is inside media folder
if (!$filePath || !str_starts_with($filePath, $mediaRoot)) {
    http_response_code(403);
    redirect_with_toast('media', 'error', 'Invalid file path.');
}

// Ensure file exists
if (!is_file($filePath)) {
    http_response_code(404);
    redirect_with_toast('media', 'error', 'File not found.');
}

// Delete
if (!unlink($filePath)) {
    http_response_code(500);
    redirect_with_toast('media', 'error', 'Failed to delete file.');
}

// Optional: delete empty parent folders
$dir = dirname($filePath);
while ($dir !== $mediaRoot && is_dir($dir) && count(scandir($dir)) === 2) {
    rmdir($dir);
    $dir = dirname($dir);
}

// Success
redirect_with_toast('media', 'success', 'File deleted.');
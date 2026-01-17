<?php

// ----------------------------
// Get slug and type
// ----------------------------
$slug = $_GET['slug'] ?? $_POST['slug'] ?? '';
$type = $_GET['type'] ?? $_POST['type'] ?? 'page';

if (!$slug) {
    redirect_with_toast('content-list', 'error', 'Missing content slug.');
}

// Sanitize slug
$slug = sanitize_slug($slug);
if (!$slug) {
    redirect_with_toast('content-list', 'error', 'Invalid content slug.');
}

// ----------------------------
// Validate content type via theme config
// ----------------------------
$theme = theme_config();
$contentTypes = $theme['content_types'] ?? [];

if (!isset($contentTypes[$type])) {
    redirect_with_toast('content-list', 'error', 'Invalid content type.');
}

// ----------------------------
// Build file path
// ----------------------------
$path = STORAGE_PATH . "/content/{$type}/{$slug}.json";

if (!is_file($path)) {
    redirect_with_toast(
        'content-list',
        'error',
        ucfirst($type) . ' not found.',
        ['type' => $type]
    );
}

// ----------------------------
// Delete
// ----------------------------
if (!unlink($path)) {
    redirect_with_toast(
        'content-list',
        'error',
        'Failed to delete ' . $type . '.',
        ['type' => $type]
    );
}

// ----------------------------
// Success
// ----------------------------
invalidate_cache($slug, $type);

redirect_with_toast(
    'content-list',
    'success',
    ucfirst($type) . ' removed successfully.',
    ['type' => $type]
);
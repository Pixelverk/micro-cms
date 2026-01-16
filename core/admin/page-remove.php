<?php

// ----------------------------
// Get slug from GET or POST
// ----------------------------
$slug = $_GET['slug'] ?? $_POST['slug'] ?? '';
if (!$slug) {
    redirect_with_toast('page-list', 'error', 'Missing page slug.');
}

// Sanitize slug (lowercase, replace spaces with dashes, remove unsafe characters)
$slug = sanitize_slug($slug);
if (!$slug) {
    redirect_with_toast('page-list', 'error', 'Invalid page slug.');
}

// Build file path
$path = STORAGE_PATH . '/pages/' . $slug . '.json';

if (!file_exists($path)) {
    redirect_with_toast('page-list', 'error', 'Page not found.');
}

// Attempt to delete
if (!unlink($path)) {
    redirect_with_toast('page-list', 'error', 'Failed to delete page.');
}

// Success
invalidate_cache();
redirect_with_toast('page-list', 'success', 'Page removed successfully.');
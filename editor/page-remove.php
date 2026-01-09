<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

// ----------------------------
// Get slug from GET or POST
// ----------------------------
$slug = $_GET['slug'] ?? $_POST['slug'] ?? '';
if (!$slug) {
    redirect_with_toast('page-list.php', 'error', 'Missing page slug.');
}

// Sanitize slug (lowercase, replace spaces with dashes, remove unsafe characters)
$slug = sanitize_slug($slug);
if (!$slug) {
    redirect_with_toast('page-list.php', 'error', 'Invalid page slug.');
}

// Build file path
$path = PAGES_DIR . '/' . $slug . '.json';

if (!file_exists($path)) {
    redirect_with_toast('page-list.php', 'error', 'Page not found.');
}

// Attempt to delete
if (!unlink($path)) {
    redirect_with_toast('page-list.php', 'error', 'Failed to delete page.');
}

// Success
redirect_with_toast('page-list.php', 'success', 'Page removed successfully.');
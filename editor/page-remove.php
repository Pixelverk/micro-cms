<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

// Get slug from GET or POST
$slug = $_GET['slug'] ?? $_POST['slug'] ?? '';
if (!$slug) {
    header('Location: page-list.php');
    exit;
}

// Sanitize slug (lowercase, replace spaces with dashes, remove unsafe characters)
$slug = sanitize_slug($slug);
if (!$slug) {
    die("Invalid page slug");
}

// Build file path
$path = PAGES_DIR . '/' . $slug . '.json';

if (!file_exists($path)) {
    die("Page not found");
}

// Attempt to delete
if (!unlink($path)) {
    die("Failed to delete page");
}

// Redirect back to page list
$_SESSION['toast'] = [
    'message' => 'Page removed',
    'type' => 'success'
];
header('Location: page-list.php?deleted=' . urlencode($slug));
exit;

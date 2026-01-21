<?php
declare(strict_types=1);

//require_once __DIR__ . '/../core/bootstrap/bootstrap.php';

$pdo = db();

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
// Check if content exists
// ----------------------------
$stmt = $pdo->prepare("SELECT id FROM content WHERE type = :type AND slug = :slug LIMIT 1");
$stmt->execute(['type' => $type, 'slug' => $slug]);
$content = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$content) {
    redirect_with_toast(
        'content-list',
        'error',
        ucfirst($type) . ' not found.',
        ['type' => $type]
    );
}

// ----------------------------
// Delete content
// ----------------------------
$stmt = $pdo->prepare("DELETE FROM content WHERE id = :id");
$stmt->execute(['id' => $content['id']]);

// ----------------------------
// Post-delete housekeeping
// ----------------------------
invalidate_cache($slug, $type);
save_sitemap();

redirect_with_toast(
    'content-list',
    'success',
    ucfirst($type) . ' removed successfully.',
    ['type' => $type]
);
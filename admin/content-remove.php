<?php
declare(strict_types=1);

$pdo = db();

// ----------------------------
// Get ID and type
// ----------------------------
$id = $_GET['id'] ?? $_POST['id'] ?? null;
$type = $_GET['type'] ?? $_POST['type'] ?? 'page';

if (!$id) {
    redirect_with_toast('content-list', 'error', 'Missing content ID.');
}

$id = (int)$id;

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
$stmt = $pdo->prepare("SELECT slug FROM content WHERE id = :id AND type = :type LIMIT 1");
$stmt->execute(['id' => $id, 'type' => $type]);
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
$stmt->execute(['id' => $id]);

// ----------------------------
// Post-delete housekeeping
// ----------------------------
invalidate_cache($content['slug'], $type);
save_sitemap();

redirect_with_toast(
    'content-list',
    'success',
    ucfirst($type) . ' removed successfully.',
    ['type' => $type]
);
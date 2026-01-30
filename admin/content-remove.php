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
// Recursive function to orphan and collect descendants
// ----------------------------
function collect_and_orphan(PDO $pdo, int $parentId): array {
    $stmt = $pdo->prepare("SELECT id, slug FROM content WHERE parent_id = :parent_id");
    $stmt->execute(['parent_id' => $parentId]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $descendantSlugs = [];

    foreach ($children as $child) {
        // Recursively process grandchildren
        $descendantSlugs = array_merge($descendantSlugs, collect_and_orphan($pdo, (int)$child['id']));

        // Orphan this child
        $update = $pdo->prepare("UPDATE content SET parent_id = NULL WHERE id = :id");
        $update->execute(['id' => $child['id']]);

        // Collect slug for cache invalidation
        $descendantSlugs[] = $child['slug'];
    }

    return $descendantSlugs;
}

// Get all descendant slugs and set their parent_id to null
$descendantSlugs = collect_and_orphan($pdo, $id);

// ----------------------------
// Delete the content
// ----------------------------
$stmt = $pdo->prepare("DELETE FROM content WHERE id = :id");
$stmt->execute(['id' => $id]);

// ----------------------------
// Invalidate cache for deleted page + descendants
// ----------------------------
invalidate_cache($content['slug'], $type);
foreach ($descendantSlugs as $slug) {
    invalidate_cache($slug, $type);
}

// ----------------------------
// Update sitemap
// ----------------------------
save_sitemap();

redirect_with_toast(
    'content-list',
    'success',
    ucfirst($type) . ' removed successfully.',
    ['type' => $type]
);
<?php
declare(strict_types=1);

// ----------------------------
// Only allow POST
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$pdo = db();
$now = time();

// ----------------------------
// Get input
// ----------------------------
$id          = !empty($_POST['id']) ? (int)$_POST['id'] : null;
$name        = trim($_POST['name'] ?? '');
$slug        = trim($_POST['slug'] ?? '');
$description = trim($_POST['description'] ?? '');
$content_type = trim($_POST['content_type'] ?? '');

// ----------------------------
// Validate
// ----------------------------
if ($name === '') {
    redirect_with_toast('category-list', 'error', 'Name is required.');
}

// auto slug
if ($slug === '') {
    $slug = slugify($name);
} else {
    $slug = slugify($slug);
}

// ----------------------------
// Ensure unique slug
// ----------------------------
$baseSlug = $slug;
$counter  = 1;

while (true) {

    if ($id) {
        // editing → ignore self
        $stmt = $pdo->prepare("
            SELECT id FROM taxonomy
            WHERE taxonomy_type = 'category'
            AND slug = ?
            AND id != ?
        ");
        $stmt->execute([$slug, $id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id FROM taxonomy
            WHERE taxonomy_type = 'category'
            AND slug = ?
        ");
        $stmt->execute([$slug]);
    }

    if (!$stmt->fetch()) break;

    $slug = $baseSlug . '-' . $counter++;
}

// ----------------------------
// Update existing
// ----------------------------
if ($id) {

    $stmt = $pdo->prepare("
        UPDATE taxonomy SET
            name        = ?,
            slug        = ?,
            description = ?,
            content_type = ?,
            updated_at  = ?
        WHERE id = ?
        AND taxonomy_type = 'category'
    ");

    $stmt->execute([
        $name,
        $slug,
        $description,
        $content_type,
        $now,
        $id
    ]);

    $msg = 'Category updated.';

}
// ----------------------------
// Insert new
// ----------------------------
else {

    $stmt = $pdo->prepare("
        INSERT INTO taxonomy (
            taxonomy_type,
            name,
            slug,
            content_type,
            description,
            created_at,
            updated_at
        ) VALUES ('category', ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $name,
        $slug,
        $content_type,
        $description,
        $now,
        $now
    ]);

    $msg = 'Category created.';
}

// ----------------------------
// Done
// ----------------------------
redirect_with_toast('category-list', 'success', $msg);
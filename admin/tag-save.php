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
$id           = !empty($_POST['id']) ? (int)$_POST['id'] : null;
$name         = trim($_POST['name'] ?? '');
$slug         = trim($_POST['slug'] ?? '');
$description  = trim($_POST['description'] ?? '');
$contentType  = trim($_POST['content_type'] ?? '');

// ----------------------------
// Validate
// ----------------------------
if ($name === '') {
    redirect_with_toast('tag-list', 'error', 'Name is required.');
}

if ($contentType === '') {
    redirect_with_toast('tag-list', 'error', 'Content type is required.');
}

// ----------------------------
// Slug
// ----------------------------
if ($slug === '') {
    $slug = slugify($name);
} else {
    $slug = slugify($slug);
}

// ----------------------------
// Ensure unique slug (per taxonomy_type)
// ----------------------------
$baseSlug = $slug;
$counter  = 1;

while (true) {

    if ($id) {
        // editing → ignore self
        $stmt = $pdo->prepare("
            SELECT id
            FROM taxonomy
            WHERE taxonomy_type = 'tag'
            AND slug = ?
            AND id != ?
        ");
        $stmt->execute([$slug, $id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id
            FROM taxonomy
            WHERE taxonomy_type = 'tag'
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
            name         = ?,
            slug         = ?,
            content_type = ?,
            description  = ?,
            updated_at   = ?
        WHERE id = ?
        AND taxonomy_type = 'tag'
    ");

    $stmt->execute([
        $name,
        $slug,
        $contentType,
        $description,
        $now,
        $id
    ]);

    $msg = 'Tag updated.';
}

// ----------------------------
// Insert new
// ----------------------------
else {

    $stmt = $pdo->prepare("
        INSERT INTO taxonomy (
            taxonomy_type,
            content_type,
            name,
            slug,
            description,
            created_at,
            updated_at
        ) VALUES (
            'tag',
            ?, ?, ?, ?, ?, ?
        )
    ");

    $stmt->execute([
        $contentType,
        $name,
        $slug,
        $description,
        $now,
        $now
    ]);

    $msg = 'Tag created.';
}

// ----------------------------
// Done
// ----------------------------
redirect_with_toast('tag-list', 'success', $msg);

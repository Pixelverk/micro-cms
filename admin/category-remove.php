<?php
declare(strict_types=1);

// ----------------------------
// POST only
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$pdo = db();

$id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$id) {
    redirect_with_toast('category-list', 'error', 'Invalid category.');
}

// ----------------------------
// Ensure it exists + is category
// ----------------------------
$stmt = $pdo->prepare("
    SELECT id, name
    FROM taxonomy
    WHERE id = ?
    AND taxonomy_type = 'category'
");
$stmt->execute([$id]);

$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    redirect_with_toast('category-list', 'error', 'Category not found.');
}

// ----------------------------
// Delete relations first
// (prevents orphans)
// ----------------------------
$stmt = $pdo->prepare("
    DELETE FROM taxonomy_term_relationships
    WHERE taxonomy_id = ?
");
$stmt->execute([$id]);

// ----------------------------
// Delete category
// ----------------------------
$stmt = $pdo->prepare("
    DELETE FROM taxonomy
    WHERE id = ?
");
$stmt->execute([$id]);

// ----------------------------
// Done
// ----------------------------
redirect_with_toast(
    'category-list',
    'success',
    'Category "' . $category['name'] . '" deleted.'
);

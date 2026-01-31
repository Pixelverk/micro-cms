<?php
declare(strict_types=1);

// ----------------------------
// Only allow POST
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$id = !empty($_POST['id']) ? (int)$_POST['id'] : null;

if (!$id) {
    redirect_with_toast('tag-list', 'error', 'Invalid tag.');
}

$pdo = db();

// ----------------------------
// Ensure tag exists
// ----------------------------
$stmt = $pdo->prepare("
    SELECT id, name
    FROM taxonomy
    WHERE id = ?
    AND taxonomy_type = 'tag'
");
$stmt->execute([$id]);

$tag = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tag) {
    redirect_with_toast('tag-list', 'error', 'Tag not found.');
}

// ----------------------------
// Delete relationships first
// ----------------------------
$pdo->prepare("
    DELETE FROM taxonomy_term_relationships
    WHERE taxonomy_id = ?
")->execute([$id]);

// ----------------------------
// Delete tag
// ----------------------------
$pdo->prepare("
    DELETE FROM taxonomy
    WHERE id = ?
    AND taxonomy_type = 'tag'
")->execute([$id]);

// ----------------------------
// Done
// ----------------------------
redirect_with_toast(
    'tag-list',
    'success',
    'Tag "' . $tag['name'] . '" deleted.'
);

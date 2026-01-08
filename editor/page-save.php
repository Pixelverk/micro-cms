<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

// Get POST data
$slug = $_POST['slug'] ?? '';
if (!$slug) {
    header('Location: page-list.php');
    exit;
}

// Load existing page JSON
$pageData = load_page($slug);
if (!$pageData) {
    die("Page not found");
}

// ----------------------------
// Update main fields
$pageData['title'] = $_POST['title'] ?? $pageData['title'] ?? '';
$pageData['meta']['description'] = $_POST['meta_description'] ?? $pageData['meta']['description'] ?? '';

// Keep layout
$pageData['layout']['header'] = $pageData['layout']['header'] ?? [];
$pageData['layout']['footer'] = $pageData['layout']['footer'] ?? [];

// ----------------------------
// Rebuild nested components from POST
$postedComponents = $_POST['components'] ?? [];

function setNestedComponent(array &$tree, array $parts, array $comp) {
    $index = array_shift($parts);

    if (!isset($tree[$index])) {
        $tree[$index] = [];
    }

    if (count($parts) === 0) {
        $tree[$index] = [
            'type' => $comp['type'] ?? '',
            'props' => $comp['props'] ?? [],
            'children' => []
        ];
        return;
    }

    if (!isset($tree[$index]['children'])) {
        $tree[$index]['children'] = [];
    }

    setNestedComponent($tree[$index]['children'], $parts, $comp);
}

$componentsTree = [];

foreach ($postedComponents as $path => $comp) {
    $parts = explode('-', $path);
    setNestedComponent($componentsTree, $parts, $comp);
}

// Reindex arrays recursively
function reindexRecursive(array $array): array {
    $result = [];
    foreach ($array as $v) {
        if (isset($v['children'])) {
            $v['children'] = reindexRecursive($v['children']);
        }
        $result[] = $v;
    }
    return $result;
}

$pageData['components'] = reindexRecursive($componentsTree);

// ----------------------------
// Save JSON page
if (!save_page($slug, $pageData)) {
    die("Failed to save page");
}

// Redirect back to editor
header("Location: page-edit.php?slug=" . urlencode($slug) . "&saved=1");
exit;
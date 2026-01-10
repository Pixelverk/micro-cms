<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

// ----------------------------
// Get POST data
// ----------------------------
$slug = $_POST['slug'] ?? '';
if (!$slug) {
    redirect_with_toast('page-list.php', 'error', 'Save - Missing page slug.');
}

// Sanitize slug
$slug = sanitize_slug($slug);
if (!$slug) {
    redirect_with_toast('page-list.php', 'error', 'Invalid page slug.');
}

// ----------------------------
// Load existing page JSON, or start fresh if creating a new page
$pageData = load_page($slug);
if (!$pageData) {
    $pageData = [
        'title' => $_POST['title'] ?? 'New Page',
        'meta' => ['description' => $_POST['meta_description'] ?? ''],
        'layout' => [
            'header' => [
                ['type' => 'site-header']
            ],
            'footer' => [
                ['type' => 'site-footer']
            ]
        ],
        'components' => []
    ];
}

// ----------------------------
// Update main fields
$pageData['title'] = $_POST['title'] ?? $pageData['title'];
$pageData['meta']['description'] = $_POST['meta_description'] ?? $pageData['meta']['description'];

// Keep layout (even for new pages)
$pageData['layout']['header'] = $pageData['layout']['header'] ?? [];
$pageData['layout']['footer'] = $pageData['layout']['footer'] ?? [];

// ----------------------------
// Rebuild nested components from POST
$postedComponents = $_POST['components'] ?? [];
$postedComponents = array_filter($postedComponents, fn($c) => !empty($c['type']));

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
// Save JSON page (will create new file if necessary)
if (!save_page($slug, $pageData)) {
    redirect_with_toast('page-list.php', 'error', 'Failed to save page.');
}

// ----------------------------
// Success toast
// ----------------------------
redirect_with_toast('page-edit.php?slug=' . urlencode($slug) . '&saved=1', 'success', 'Page saved successfully!');
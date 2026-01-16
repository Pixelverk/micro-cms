<?php

// ----------------------------
// Get POST data
// ----------------------------
$slug = $_POST['slug'] ?? '';
if (!$slug) {
    redirect_with_toast('page-list', 'error', 'Save - Missing page slug.');
}

$slug = sanitize_slug($slug);
if (!$slug) {
    redirect_with_toast('page-list', 'error', 'Invalid page slug.');
}

// ----------------------------
// Load existing page JSON or create new
// ----------------------------
$pageData = load_page($slug) ?: [];

// ----------------------------
// Update basic fields
// ----------------------------
$pageData['title'] = trim($_POST['title'] ?? $pageData['title'] ?? 'New Page');
$pageData['meta']['description'] = trim(
    $_POST['meta_description'] ?? $pageData['meta']['description'] ?? ''
);

// ----------------------------
// Layout / header / footer
// ----------------------------
$layout = $_POST['layout'] ?? null;
$header = $_POST['header'] ?? null;
$footer = $_POST['footer'] ?? null;

// Only save if explicitly set (otherwise let defaults apply)
if ($layout !== null && $layout !== '') {
    $pageData['layout'] = $layout;
} else {
    unset($pageData['layout']);
}

if ($header !== null && $header !== '') {
    $pageData['header'] = $header;
} else {
    unset($pageData['header']);
}

if ($footer !== null && $footer !== '') {
    $pageData['footer'] = $footer;
} else {
    unset($pageData['footer']);
}

// ----------------------------
// Rebuild nested components from POST
// ----------------------------
$postedComponents = $_POST['components'] ?? [];
$postedComponents = array_filter(
    $postedComponents,
    fn ($c) => !empty($c['type'])
);

function setNestedComponent(array &$tree, array $parts, array $comp): void {
    $index = array_shift($parts);

    if (!isset($tree[$index])) {
        $tree[$index] = [];
    }

    if (count($parts) === 0) {
        $tree[$index] = [
            'type'     => $comp['type'],
            'props'    => $comp['props'] ?? [],
            'children' => [],
        ];
        return;
    }

    $tree[$index]['children'] ??= [];
    setNestedComponent($tree[$index]['children'], $parts, $comp);
}

$componentsTree = [];
foreach ($postedComponents as $path => $comp) {
    $parts = explode('-', $path);
    setNestedComponent($componentsTree, $parts, $comp);
}

// Reindex recursively
function reindexRecursive(array $array): array {
    $result = [];
    foreach ($array as $item) {
        if (isset($item['children'])) {
            $item['children'] = reindexRecursive($item['children']);
        }
        $result[] = $item;
    }
    return $result;
}

$pageData['components'] = reindexRecursive($componentsTree);

// ----------------------------
// Save page JSON
// ----------------------------
if (!save_page($slug, $pageData)) {
    redirect_with_toast('page-list', 'error', 'Failed to save page.');
}

// ----------------------------
// Success
// ----------------------------
redirect_with_toast(
    'page-edit',
    'success',
    'Page saved successfully!',
    [
        'slug'  => $slug,
        'saved' => 1,
    ]
);
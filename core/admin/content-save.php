<?php

// ----------------------------
// Get POST data
// ----------------------------
$slug = $_POST['slug'] ?? '';
$contentType = $_POST['type'] ?? 'page'; // default to 'page'

if (!$slug) {
    redirect_with_toast('content-list', 'error', "Save - Missing slug for {$contentType}.");
}

$slug = sanitize_slug($slug);
if (!$slug) {
    redirect_with_toast('content-list', 'error', "Invalid slug for {$contentType}.");
}

// ----------------------------
// Load existing content JSON or create new
// ----------------------------
$contentData = load_content($contentType, $slug) ?: [];

// ----------------------------
// Update basic fields
// ----------------------------
$contentData['type'] = $contentType;
$contentData['title'] = trim($_POST['title'] ?? $contentData['title'] ?? "New {$contentType}");
$contentData['meta']['description'] = trim(
    $_POST['meta_description'] ?? $contentData['meta']['description'] ?? ''
);

// ----------------------------
// Layout / header / footer
// ----------------------------
$layout = $_POST['layout'] ?? null;
$header = $_POST['header'] ?? null;
$footer = $_POST['footer'] ?? null;

// Only save if explicitly set
if ($layout !== null && $layout !== '') {
    $contentData['layout'] = $layout;
} else {
    unset($contentData['layout']);
}

if ($header !== null && $header !== '') {
    $contentData['header'] = $header;
} else {
    unset($contentData['header']);
}

if ($footer !== null && $footer !== '') {
    $contentData['footer'] = $footer;
} else {
    unset($contentData['footer']);
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

$contentData['components'] = reindexRecursive($componentsTree);

// ----------------------------
// Save content JSON
// ----------------------------
if (!save_content($contentType, $slug, $contentData)) {
    redirect_with_toast("content-list", 'error', "Failed to save {$contentType}.");
}

// ----------------------------
// Success
// ----------------------------
redirect_with_toast(
    'content-edit',
    'success',
    ucfirst($contentType) . ' saved successfully!',
    [
        'slug'  => $slug,
        'type'  => $contentType,
        'saved' => 1,
    ]
);
<?php
// admin/content-save.php

// ----------------------------
// Get POST data
// ----------------------------
$id = $_POST['id'] ?? null; // editing existing content
$contentType = $_POST['type'] ?? 'page'; // default type

// ----------------------------
// Load existing content or create new
// ----------------------------
$contentData = [];

if ($id) {
    $contentData = load_content_by_id((int)$id) ?: [];
}

// ----------------------------
// Slug handling
// ----------------------------
$slug = $_POST['slug'] ?? '';

if (!$slug) {
    redirect_with_toast('content-list', 'error', "Save - Missing slug for {$contentType}.");
}

$slug = sanitize_slug($slug);
if (!$slug) {
    redirect_with_toast('content-list', 'error', "Invalid slug for {$contentType}.");
}

$contentData['slug'] = $slug;

// ----------------------------
// Parent ID / Nested Pages
// ----------------------------
$parentId = $_POST['parent_id'] ?? null;
$parentId = ($parentId === '' || $parentId === null) ? null : (int) $parentId;

if ($id && $parentId) {
    // Prevent self or descendant as parent
    function get_descendant_ids(int $id, array $allItems): array {
        $descendants = [];
        foreach ($allItems as $item) {
            if (($item['parent_id'] ?? null) === $id) {
                $descendants[] = $item['id'];
                $descendants = array_merge($descendants, get_descendant_ids($item['id'], $allItems));
            }
        }
        return $descendants;
    }

    $allItems = list_content($contentType);
    $invalidParentIds = array_merge([$id], get_descendant_ids($id, $allItems));

    if (in_array($parentId, $invalidParentIds, true)) {
        $parentId = null; // reset to top level
    }
}

$contentData['parent_id'] = $parentId;

// ----------------------------
// Status & timestamps
// ----------------------------
$status = $_POST['status'] ?? 'published';
$contentData['status'] = $status;

$currentTime = time();
$contentData['updated_at'] = $currentTime;
if (!isset($contentData['created_at'])) {
    $contentData['created_at'] = $currentTime;
}

// ----------------------------
// Basic fields
// ----------------------------
$contentData['type'] = $contentType;
$contentData['title'] = trim($_POST['title'] ?? $contentData['title'] ?? "New {$contentType}");
$contentData['meta'] ??= [];
$contentData['meta']['description'] = trim($_POST['meta_description'] ?? $contentData['meta']['description'] ?? '');

// ----------------------------
// Layout / header / footer
// ----------------------------
$layout = $_POST['layout'] ?? null;
$header = $_POST['header'] ?? null;
$footer = $_POST['footer'] ?? null;

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
    fn($c) => !empty($c['type'])
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

$contentData['body'] ??= [];
$contentData['body'] = reindexRecursive($componentsTree);

// ----------------------------
// Save content
// ----------------------------
$id = save_content($contentType, $slug, $contentData, $id);

if (!$id) {
    redirect_with_toast("content-list", 'error', "Failed to save {$contentType}.");
}

// ----------------------------
// Success redirect
// ----------------------------
redirect_with_toast(
    'content-edit',
    'success',
    ucfirst($contentType) . ' saved successfully!',
    [
        'id'    => $id,
        'type'  => $contentType,
        'saved' => 1,
    ]
);
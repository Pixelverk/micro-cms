<?php
// admin/content-save.php

declare(strict_types=1);

// ----------------------------
// Get POST data
// ----------------------------
$id          = $_POST['id'] ?? null; // editing existing content
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
$slug = trim($_POST['slug'] ?? '');
if (!$slug) {
    redirect_with_toast('content', 'error', "Save - Missing slug for {$contentType}.");
}
$slug = sanitize_slug($slug);
if (!$slug) {
    redirect_with_toast('content', 'error', "Invalid slug for {$contentType}.");
}
$contentData['slug'] = $slug;

// ----------------------------
// Parent ID / Nested Pages
// ----------------------------
$parentId = $_POST['parent_id'] ?? null;
$parentId = ($parentId === '' || $parentId === null) ? null : (int)$parentId;

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
// Permissions
// ----------------------------
$existingForPermission = $id ? load_content_by_id((int) $id) : null;

if ($id === null) {
    require_capability('content.create');
} else {
    // Authors may only edit content they created.
    if (!$existingForPermission || !can_edit_content($existingForPermission)) {
        log_activity('security.forbidden', 'content', (int) $id, 'content.edit', []);
        http_response_code(403);
        render_admin_forbidden('content.edit.own');
        exit;
    }
}

// Publishing is a separate capability from editing.
if (($_POST['status'] ?? 'draft') === 'published' && !admin_can('content.publish')) {
    log_activity('security.forbidden', 'content', $id !== null ? (int) $id : null, 'content.publish', []);
    http_response_code(403);
    render_admin_forbidden('content.publish');
    exit;
}

// ----------------------------
// Validate the request before touching the database
// ----------------------------
$theme        = theme_config();
$contentTypes = $theme['content_types'] ?? [];

if (!isset($contentTypes[$contentType])) {
    redirect_with_toast('content', 'error', 'Invalid content type.');
}

$ctConfig = $contentTypes[$contentType];
$errors   = [];

if ($slug === '') {
    $errors['slug'] = 'A slug is required.';
} elseif (!validate_slug($slug)) {
    $errors['slug'] = 'The slug may only contain lowercase letters, numbers and dashes.';
}

$status = (string) ($_POST['status'] ?? 'draft');
if (!validate_enum($status, content_statuses())) {
    $errors['status'] = 'Unknown status.';
}

$title = trim((string) ($_POST['title'] ?? $contentData['title'] ?? ''));
if ($title === '') {
    $errors['title'] = 'A title is required.';
}

$layout = (string) ($_POST['layout'] ?? '');
if ($layout !== '' && !array_key_exists($layout, $theme['layouts'] ?? [])) {
    $errors['layout'] = 'Unknown layout.';
}

$header = (string) ($_POST['header'] ?? '');
if ($header !== '' && !array_key_exists($header, $theme['headers'] ?? [])) {
    $errors['header'] = 'Unknown header.';
}

$footer = (string) ($_POST['footer'] ?? '');
if ($footer !== '' && !array_key_exists($footer, $theme['footers'] ?? [])) {
    $errors['footer'] = 'Unknown footer.';
}

// A parent must exist and belong to the same content type.
if ($parentId !== null) {
    $parentStmt = db()->prepare("SELECT id FROM content WHERE id = :id AND type = :type LIMIT 1");
    $parentStmt->execute(['id' => $parentId, 'type' => $contentType]);

    if (!$parentStmt->fetchColumn()) {
        $errors['parent_id'] = 'That parent page no longer exists.';
    }
}

// The scheduled date is entered in the site timezone.
$scheduledRaw = trim((string) ($_POST['scheduled_at'] ?? ''));
$scheduledAt  = null;

if ($status === 'scheduled') {
    if ($scheduledRaw === '') {
        $errors['scheduled_at'] = 'Choose a date and time to publish.';
    } else {
        $scheduledAt = validate_local_datetime($scheduledRaw, SITE_TIMEZONE);

        if ($scheduledAt === null) {
            $errors['scheduled_at'] = 'That publish date could not be understood.';
        } elseif (!validate_future_timestamp($scheduledAt)) {
            $errors['scheduled_at'] = 'The publish date must be in the future.';
        }
    }
}

// Taxonomy selections must exist and match this content type.
$categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
if ($categoryId) {
    $catStmt = db()->prepare("SELECT id FROM taxonomy WHERE id = :id AND taxonomy_type = 'category' LIMIT 1");
    $catStmt->execute(['id' => $categoryId]);

    if (!$catStmt->fetchColumn()) {
        $errors['category_id'] = 'That category no longer exists.';
    }
}

$tagIds = array_values(array_filter(array_map('intval', (array) ($_POST['tag_ids'] ?? []))));
if ($tagIds) {
    $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
    $tagStmt = db()->prepare("SELECT COUNT(*) FROM taxonomy WHERE taxonomy_type = 'tag' AND id IN ({$placeholders})");
    $tagStmt->execute($tagIds);

    if ((int) $tagStmt->fetchColumn() !== count($tagIds)) {
        $errors['tag_ids'] = 'One or more selected tags no longer exist.';
    }
}

if ($errors) {
    validate_throw($errors, 'content/edit?id=' . (int) $id . '&type=' . urlencode($contentType));
}

// ----------------------------
// Status, scheduled_at & timestamps
// ----------------------------
$currentTime = time(); // UTC

// Ensure timestamps exist
$contentData['updated_at'] = $currentTime;
$contentData['created_at'] ??= $currentTime;

// The editor posts status "scheduled" plus a local date/time; resolve_content_status()
// derives the stored status/timestamps exactly as save_content() will.
$resolved = resolve_content_status([
    'status'       => $status,
    'scheduled_at' => $scheduledAt,
    'published_at' => $contentData['published_at'] ?? null,
], $currentTime);

$status                     = $resolved['status'];
$contentData['status']      = $status;
$contentData['published_at'] = $resolved['published_at'];
$contentData['scheduled_at'] = $resolved['scheduled_at'];

// ----------------------------
// Basic fields
// ----------------------------
$contentData['type']  = $contentType;
$contentData['title'] = $title;
$contentData['meta'] ??= [];
$contentData['meta']['description'] = trim($_POST['meta_description'] ?? $contentData['meta']['description'] ?? '');

// SEO & social fields: trimmed, length-capped and validated.
$contentData['meta'] = seo_collect_meta($_POST, $contentData['meta']);

$canonical = (string) ($contentData['meta']['canonical'] ?? '');
if ($canonical !== '' && !seo_validate_canonical($canonical)) {
    $errors['meta_canonical'] = 'The canonical URL must be an absolute address on this site.';
}

$robotsExtra = (string) ($contentData['meta']['robots_extra'] ?? '');
if ($robotsExtra !== '' && !preg_match('/^[a-z]+(,\s*[a-z]+)*$/', $robotsExtra)) {
    $errors['meta_robots_extra'] = 'Robots override should look like "noindex, follow".';
}

// Every field has now been read and validated; stop before writing anything.
if ($errors) {
    validate_throw($errors, 'content/edit?id=' . (int) $id . '&type=' . urlencode($contentType));
}

// ----------------------------
// Layout / header / footer
// ----------------------------
if ($layout !== '') $contentData['layout'] = $layout; else unset($contentData['layout']);
if ($header !== '') $contentData['header'] = $header; else unset($contentData['header']);
if ($footer !== '') $contentData['footer'] = $footer; else unset($contentData['footer']);

// ----------------------------
// Rebuild nested components from POST
// ----------------------------
$postedComponents = $_POST['components'] ?? [];
$postedComponents = array_filter($postedComponents, fn($c) => !empty($c['type']));

function setNestedComponent(array &$tree, array $parts, array $comp): void {
    $index = array_shift($parts);
    if (!isset($tree[$index])) $tree[$index] = [];
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
    $parts = explode('-', (string)$path);
    setNestedComponent($componentsTree, $parts, $comp);
}

function reindexRecursive(array $array): array {
    $result = [];
    foreach ($array as $item) {
        if (isset($item['children'])) $item['children'] = reindexRecursive($item['children']);
        $result[] = $item;
    }
    return $result;
}

$contentData['body'] = reindexRecursive($componentsTree);

// ----------------------------
// Save content
// ----------------------------
$isNew = empty($id);

try {
    $id = save_content($contentType, $slug, $contentData, $id !== null ? (int) $id : null, [
        // Publish/status transitions are worth labelling in the history.
        'reason' => $status === 'published' ? 'publish' : 'save',
    ]);
} catch (PDOException $exception) {
    // Most likely UNIQUE(type, parent_id, slug): a sibling already uses it.
    if (str_contains($exception->getMessage(), 'UNIQUE')) {
        redirect_with_toast(
            'content',
            'error',
            "The slug \"{$slug}\" is already used by another " . strtolower($ctConfig['label'] ?? $contentType) . ' at this level.'
        );
    }

    throw $exception;
}

if (!$id) {
    redirect_with_toast("content", 'error', "Failed to save {$contentType}.");
}

$pdo = db();

// ----------------------------
// Taxonomy relationships
// ----------------------------
// Replace all links for this item in one pass.
$pdo->prepare("
    DELETE FROM taxonomy_term_relationships
    WHERE content_type = ?
    AND content_id = ?
")->execute([$contentType, $id]);

if ($categoryId) {
    $pdo->prepare("
        INSERT INTO taxonomy_term_relationships
        (content_type, content_id, taxonomy_id)
        VALUES (?, ?, ?)
    ")->execute([$contentType, $id, $categoryId]);
}

// insert selected tags
$stmt = $pdo->prepare("
    INSERT OR IGNORE INTO taxonomy_term_relationships
    (content_type, content_id, taxonomy_id)
    VALUES (?, ?, ?)
");

foreach ($tagIds as $tagId) {
    $stmt->execute([$contentType, $id, $tagId]);
}


// ----------------------------
// Success redirect
// ----------------------------
log_activity(
    $isNew
        ? 'content.created'
        : ($status === 'published' ? 'content.published' : 'content.updated'),
    'content',
    (int) $id,
    $title,
    ['type' => $contentType, 'status' => $status]
);

redirect_with_toast(
    'content/edit',
    'success',
    ucfirst($contentType) . ' saved successfully!',
    [
        'id'    => $id,
        'type'  => $contentType,
        'saved' => 1,
    ]
);
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
    $contentData = load_content_by_id_admin((int)$id) ?: [];
}

// ----------------------------
// Slug handling
// ----------------------------
$slug = trim($_POST['slug'] ?? '');
if (!$slug) {
    redirect_with_toast('content', 'error', admin_trans('content_error_missing_slug', ['type' => $contentType]));
}
$slug = sanitize_slug($slug);
if (!$slug) {
    redirect_with_toast('content', 'error', admin_trans('content_error_invalid_slug', ['type' => $contentType]));
}
$contentData['slug'] = $slug;

// ----------------------------
// Parent ID / Nested Pages
// ----------------------------
$parentId = $_POST['parent_id'] ?? null;
$parentId = ($parentId === '' || $parentId === null) ? null : (int)$parentId;

if ($id && $parentId) {
    // Prevent self or descendant as parent
    $allItems = list_content_admin($contentType);
    $invalidParentIds = array_merge([$id], content_descendant_ids($id, $allItems));
    if (in_array($parentId, $invalidParentIds, true)) {
        $parentId = null; // reset to top level
    }
}
$contentData['parent_id'] = $parentId;

// ----------------------------
// Permissions
// ----------------------------
// $contentData already holds the stored row; slug and parent_id are not part
// of the ownership check, so there is no need to load it a second time.
$existingForPermission = $id ? $contentData : null;

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
    redirect_with_toast('content', 'error', admin_trans('content_error_type'));
}

$ctConfig = $contentTypes[$contentType];
$errors   = [];

if ($slug === '') {
    $errors['slug'] = admin_trans('content_error_slug_required');
} elseif (!validate_slug($slug)) {
    $errors['slug'] = admin_trans('content_error_slug_format');
}

$status = (string) ($_POST['status'] ?? 'draft');
if (!validate_enum($status, content_statuses())) {
    $errors['status'] = admin_trans('content_error_status');
}

$title = trim((string) ($_POST['title'] ?? $contentData['title'] ?? ''));
if ($title === '') {
    $errors['title'] = admin_trans('content_error_title');
}

$layout = (string) ($_POST['layout'] ?? '');
if ($layout !== '' && !array_key_exists($layout, $theme['layouts'] ?? [])) {
    $errors['layout'] = admin_trans('content_error_layout');
}

$header = (string) ($_POST['header'] ?? '');
if ($header !== '' && !array_key_exists($header, $theme['headers'] ?? [])) {
    $errors['header'] = admin_trans('content_error_header');
}

$footer = (string) ($_POST['footer'] ?? '');
if ($footer !== '' && !array_key_exists($footer, $theme['footers'] ?? [])) {
    $errors['footer'] = admin_trans('content_error_footer');
}

// A parent must exist and belong to the same content type.
if ($parentId !== null) {
    $parentStmt = db()->prepare("SELECT id FROM content WHERE id = :id AND type = :type AND deleted_at IS NULL LIMIT 1");
    $parentStmt->execute(['id' => $parentId, 'type' => $contentType]);

    if (!$parentStmt->fetchColumn()) {
        $errors['parent_id'] = admin_trans('content_error_parent');
    }
}

// The scheduled date is entered in the site timezone.
$scheduledRaw = trim((string) ($_POST['scheduled_at'] ?? ''));
$scheduledAt  = null;

if ($status === 'scheduled') {
    if ($scheduledRaw === '') {
        $errors['scheduled_at'] = admin_trans('content_error_schedule_missing');
    } else {
        $scheduledAt = validate_local_datetime($scheduledRaw, site_timezone());

        if ($scheduledAt === null) {
            $errors['scheduled_at'] = admin_trans('content_error_schedule_invalid');
        } elseif (!validate_future_timestamp($scheduledAt)) {
            $errors['scheduled_at'] = admin_trans('content_error_schedule_past');
        }
    }
}

// Taxonomy selections must exist and match this content type.
$categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
if ($categoryId) {
    $catStmt = db()->prepare("SELECT id FROM taxonomy WHERE id = :id AND taxonomy_type = 'category' LIMIT 1");
    $catStmt->execute(['id' => $categoryId]);

    if (!$catStmt->fetchColumn()) {
        $errors['category_id'] = admin_trans('content_error_category');
    }
}

$tagIds = array_values(array_filter(array_map('intval', (array) ($_POST['tag_ids'] ?? []))));
if ($tagIds) {
    $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
    $tagStmt = db()->prepare("SELECT COUNT(*) FROM taxonomy WHERE taxonomy_type = 'tag' AND id IN ({$placeholders})");
    $tagStmt->execute($tagIds);

    if ((int) $tagStmt->fetchColumn() !== count($tagIds)) {
        $errors['tag_ids'] = admin_trans('content_error_tags');
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

// Presentation images (thumbnail, gallery) the content type declares.
$contentData['meta'] = content_collect_images(
    $_POST,
    $contentData['meta'],
    is_array($ctConfig['images'] ?? null) ? $ctConfig['images'] : []
);

// Meta fields the content type declares (a project URL, an excerpt, …).
$ctMetaFields = content_meta_fields($ctConfig);
$contentData['meta'] = content_collect_meta_fields($_POST, $contentData['meta'], $ctMetaFields);

foreach ($ctMetaFields as $metaFieldKey => $metaField) {
    $metaFieldError = content_meta_field_error($metaField, $contentData['meta'][$metaFieldKey] ?? '');

    if ($metaFieldError !== '') {
        $errors['meta_' . $metaFieldKey] = $metaFieldError;
    }
}

$canonical = (string) ($contentData['meta']['canonical'] ?? '');
if ($canonical !== '' && !seo_validate_canonical($canonical)) {
    $errors['meta_canonical'] = admin_trans('content_error_canonical');
}

$robotsExtra = (string) ($contentData['meta']['robots_extra'] ?? '');
if ($robotsExtra !== '' && !preg_match('/^[a-z]+(,\s*[a-z]+)*$/', $robotsExtra)) {
    $errors['meta_robots_extra'] = admin_trans('content_error_robots');
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
// A component is anything that names a type or carries props. A request from a
// stale editor may post only a rich-text field, and dropping it here would drop
// the one component there is.
$postedComponents = array_filter(
    $postedComponents,
    static fn($c) => is_array($c) && (($c['type'] ?? '') !== '' || ($c['props'] ?? []) !== [])
);

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

// A rich-text-only content type stores exactly the one component its manifest
// names, whatever the request posted, so the editor's output and the stored
// body cannot drift apart.
$contentData['body'] = content_rich_text_body($ctConfig, $contentData['body']);

// ----------------------------
// Autosave
// ----------------------------
// The editor posts the same form with autosave=1 every minute. Everything
// above has already capability-checked and validated it, so the snapshot is
// exactly the state a real save would write — only the live row is left alone.
// It writes a version, not public content, so nothing needs invalidating.
if (!empty($_POST['autosave']) && $id !== null) {
    $versionId = save_content_version((int) $id, $contentData, ['reason' => 'autosave']);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'       => true,
        'saved_at' => time(),
        'version'  => $versionId,
    ]);
    exit;
}

// ----------------------------
// Pre-publish checklist
// ----------------------------
// Blocking rules keep half-finished content off the live site. The save still
// goes ahead as a draft, so the editor's work is kept and it can show exactly
// what is missing when it reloads.
$blockedPublish = null;

$storedStatus = (string) ($existingForPermission['status'] ?? 'draft');

if ($status === 'published' && $storedStatus !== 'published') {
    $blockers = content_checklist_blockers(content_publish_checklist($contentData));

    if ($blockers) {
        $reasons = array_map(
            static fn(array $item): string => admin_trans('checklist_rule_' . $item['rule'])
                . ($item['detail'] !== '' ? ' (' . $item['detail'] . ')' : ''),
            $blockers
        );

        $blockedPublish = admin_trans('editor_publish_blocked', [
            'list' => implode('; ', array_slice($reasons, 0, 5)),
        ]);

        $status                      = 'draft';
        $contentData['status']       = 'draft';
        $contentData['published_at'] = null;
        $contentData['scheduled_at'] = null;
    }
}

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
            admin_trans('content_error_slug_taken', [
                'slug' => $slug,
                'type' => strtolower($ctConfig['label'] ?? $contentType),
            ])
        );
    }

    throw $exception;
} catch (RuntimeException $exception) {
    // e.g. the slug belongs to an item in the trash (see save_content()).
    redirect_with_toast('content', 'error', $exception->getMessage());
}

if (!$id) {
    redirect_with_toast("content", 'error', admin_trans('content_error_save', ['type' => $contentType]));
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
    $blockedPublish !== null ? 'error' : 'success',
    $blockedPublish ?? admin_trans('content_saved', ['type' => ucfirst($contentType)]),
    [
        'id'    => $id,
        'type'  => $contentType,
        'saved' => 1,
    ]
);
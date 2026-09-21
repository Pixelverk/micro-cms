<?php

$pageTitle = admin_trans('editor_title');

// ----------------------------
// Determine mode
// ----------------------------
$type = $_GET['type'] ?? 'page';
$slug = $_GET['slug'] ?? '';

// ----------------------------
// Load theme & settings
// ----------------------------
$theme    = theme_config();
$settings = load_settings();
$homepageSlug = content_homepage_slug();

$contentTypes = $theme['content_types'] ?? [];
$ctConfig     = $contentTypes[$type] ?? [];

$prefix = $settings['content_prefixes'][$type] ?? $ctConfig['url_prefix'] ?? '';
$url = '/' . ($prefix ? $prefix . '/' : '') . $slug;

$typeLabel = $ctConfig['label'] ?? ucfirst($type);

// ----------------------------
// Load content (edit mode)
// ----------------------------
$contentData = null;

$id = $_GET['id'] ?? null;
if ($id) {
    $contentData = load_content_by_id_admin((int)$id);

    if (!$contentData) {
        redirect_with_toast(
            "content/?type={$type}",
            'error',
            admin_trans('content_error_not_found', ['type' => $typeLabel])
        );
    }

    $slug = $contentData['slug']; // keep old $slug variable for form display
}

// if there is contentdata, we're editing existing page
$isEdit = !empty($contentData);

// ----------------------------
// Discard a pending autosave
// ----------------------------
// The notice's Dismiss button discards the draft rather than only hiding the
// notice, so a draft the editor does not want cannot linger forever.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'discard_autosave') {
    if (!$isEdit || !can_edit_content($contentData)) {
        log_activity('security.forbidden', 'content', $id !== null ? (int) $id : null, 'content.edit.own', []);
        http_response_code(403);
        render_admin_forbidden('content.edit.own');
        exit;
    }

    $versionId = (int) ($_POST['version_id'] ?? 0);
    $version   = load_content_version($versionId);

    // Only this item's own autosave can be dismissed.
    if ($version && (int) $version['content_id'] === (int) $contentData['id'] && $version['reason'] === 'autosave') {
        delete_content_version($versionId);

        log_activity('content.autosave_discarded', 'content', (int) $contentData['id'], (string) $contentData['title']);
    }

    redirect_with_toast('content/edit', 'success', admin_trans('editor_autosave_dismissed'), [
        'id'   => (int) $contentData['id'],
        'type' => $type,
    ]);
}

// An autosave newer than the stored row is unsaved work from a tab that went
// away. It is reviewed and restored through the normal version history.
$pendingAutosave = null;

if ($isEdit) {
    $pendingAutosave = latest_content_autosave((int) $contentData['id']);

    if ($pendingAutosave && (int) $pendingAutosave['created_at'] <= (int) ($contentData['updated_at'] ?? 0)) {
        $pendingAutosave = null;
    }
}

// ----------------------------
// Load an autosave into the editor
// ----------------------------
// Restoring a draft only fills the form; it never writes the row. Writing it
// would save whatever status the form carried and could publish half-finished
// work. Saving is the explicit step that keeps it.
$restoringAutosave = null;

if ($pendingAutosave && (int) ($_GET['restore_version'] ?? 0) === (int) $pendingAutosave['id']) {
    $restoringAutosave = $pendingAutosave;

    $restoredMeta = json_decode((string) $restoringAutosave['meta'], true);
    $restoredBody = json_decode((string) $restoringAutosave['body'], true);

    $contentData['title']        = (string) $restoringAutosave['title'];
    $contentData['status']       = (string) $restoringAutosave['status'];
    $contentData['layout']       = $restoringAutosave['layout'];
    $contentData['header']       = $restoringAutosave['header'];
    $contentData['footer']       = $restoringAutosave['footer'];
    $contentData['meta']         = is_array($restoredMeta) ? $restoredMeta : [];
    $contentData['body']         = is_array($restoredBody) ? $restoredBody : [];
    $contentData['published_at'] = $restoringAutosave['published_at'];
    $contentData['scheduled_at'] = $restoringAutosave['scheduled_at'];
}

// Pre-publish checklist for whatever the editor is currently showing, so a
// loaded draft is checked as well as the saved row.
$publishChecklist = $isEdit ? content_publish_checklist($contentData) : [];

// ----------------------------
// Content values
// ----------------------------
$title           = $contentData['title'] ?? '';
$status          = $contentData['status'] ?? 'draft';
$metaDescription = $contentData['meta']['description'] ?? '';
$components      = $contentData['body'] ?? [];

// SEO panel: keep the existing meta array.
$meta = is_array($contentData['meta'] ?? null) ? $contentData['meta'] : [];

// Meta fields the content type declares (a project URL, an excerpt, …). The
// theme's own keys, stored beside the SEO ones in the same meta array.
$ctMetaFields = content_meta_fields($ctConfig);

$scheduledDate = '';
if (!empty($contentData['scheduled_at'])) {
    $dt = new DateTime(
        '@' . (int) $contentData['scheduled_at'] // force UTC
    );
    $dt->setTimezone(new DateTimeZone(site_timezone()));

    $scheduledDate = $dt->format('Y-m-d\TH:i');
}

// parent stuff
$allItems = list_content_admin($type);

if ($isEdit) {
    $fullSlug = build_full_slug($contentData, $allItems);
    $url = '/' . ($prefix ? $prefix . '/' : '') . $fullSlug;
}

// Parent options
$allParents = $allItems;
$currentId = $contentData['id'] ?? null;
$currentParentId = $contentData['parent_id'] ?? null;

// exclude self and descendants from parent options
$excludeIds = $currentId ? array_merge([$currentId], content_descendant_ids($currentId, $allParents)) : [];
$parentOptions = array_filter($allParents, fn($p) => !in_array($p['id'], $excludeIds, true));

// categories

$pdo = db();

$stmt = $pdo->prepare("
    SELECT *
    FROM taxonomy
    WHERE taxonomy_type = 'category'
    AND content_type = ?
    ORDER BY name
");

$stmt->execute([$type]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedCategoryId = null;

if ($isEdit && !empty($contentData['id'])) {
    $stmt = $pdo->prepare("
        SELECT taxonomy_id
        FROM taxonomy_term_relationships
        WHERE content_type = ?
        AND content_id = ?
        LIMIT 1
    ");

    $stmt->execute([$type, $contentData['id']]);
    $selectedCategoryId = $stmt->fetchColumn() ?: null;
}

// tags

// ----------------------------
// Load tags
// ----------------------------
$stmt = $pdo->prepare("
    SELECT *
    FROM taxonomy
    WHERE taxonomy_type = 'tag'
    AND content_type = ?
    ORDER BY name
");
$stmt->execute([$type]);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

// selected tags
$selectedTagIds = [];

if ($isEdit && !empty($contentData['id'])) {
    $stmt = $pdo->prepare("
        SELECT taxonomy_id
        FROM taxonomy_term_relationships
        WHERE content_type = ?
        AND content_id = ?
    ");
    $stmt->execute([$type, $contentData['id']]);

    $selectedTagIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// image files
$stmt = $pdo->prepare("
    SELECT id, original_name, base_path, mime_type, original_size, formats_json, sizes_json
    FROM media
    WHERE mime_type LIKE 'image/%'
    ORDER BY created_at DESC
");
$stmt->execute();
$mediaImages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare JS-friendly array
$mediaImagesJs = array_map(function($row) {
    return [
        'id'            => (int)$row['id'],
        'original_name' => $row['original_name'],
        'base_path'     => $row['base_path'],
        'mime'          => $row['mime_type'],
        'size'          => (int)$row['original_size'],
        'formats'       => json_decode($row['formats_json'] ?? '{}', true) ?: [],
        'sizes'         => json_decode($row['sizes_json'] ?? '{}', true) ?: [],
    ];
}, $mediaImages);

// ----------------------------
// Layout / header / footer defaults
// ----------------------------
$availableLayouts = $theme['layouts'] ?? [];
$availableHeaders = $theme['headers'] ?? [];
$availableFooters = $theme['footers'] ?? [];

$pageLayout = $contentData['layout']
    ?? $ctConfig['default_layout']
    ?? $settings['default_layout']
    ?? $theme['defaults']['layout'];

$pageHeader = $contentData['header']
    ?? $ctConfig['default_header']
    ?? $settings['default_header']
    ?? $theme['defaults']['header'];

$pageFooter = $contentData['footer']
    ?? $ctConfig['default_footer']
    ?? $settings['default_footer']
    ?? $theme['defaults']['footer'];

// ----------------------------
// Load allowed components
// ----------------------------
// A content type that declares 'editor' => 'rich-text' in theme.php is written
// as one rich-text field: the component its palette names is resolved here, and
// the palette itself is not loaded because there is nothing to add.
$richTextEditor = content_rich_text_editor($ctConfig);

$allowedComponents = $ctConfig['available_components'] ?? [];

$availableComponents = [];

if ($richTextEditor === null) {
    $coreComponentFiles = glob(CORE_PATH . '/components/*.php');
    $themeComponentFiles = glob(CMS_PATH . '/theme/components/*.php');
    $componentFiles = array_merge($coreComponentFiles, $themeComponentFiles);

    foreach ($componentFiles as $file) {
        $name = basename($file, '.php');

        if (!empty($allowedComponents) && !in_array($name, $allowedComponents, true)) {
            continue;
        }

        $component = require $file;

        $schema = $component['schema'] ?? [];

        // A component's "menu" field points at one of the slots declared in
        // theme.php's menu_locations. The options are filled in here so the
        // manifest stays the single source of truth for what slots exist.
        if (isset($schema['menu']) && ($schema['menu']['type'] ?? '') === 'select') {
            $schema['menu']['options'] = $theme['menu_locations'] ?? [];
        }

        // A "content_type" field lets a listing choose which content type it
        // renders. Same rule: the theme manifest decides what can be listed.
        if (isset($schema['content_type']) && ($schema['content_type']['type'] ?? '') === 'select') {
            $schema['content_type']['options'] = array_map(
                static fn(array $config) => $config['label'] ?? 'Unnamed',
                $theme['content_types'] ?? []
            );
        }

        // A field may declare how wide it wants to be. The values are the
        // helper's, so a typo becomes automatic rather than a class the
        // stylesheet does not know.
        $schema = content_component_field_schema($schema);

        $availableComponents[$name] = [
            'label'            => $component['label'] ?? $name,
            'description'      => $component['description'] ?? '',
            'preview'          => component_preview_url($name),
            'schema'           => $schema,
            'children'         => $component['children'] ?? 'any',
            'allowed_children' => $component['allowed_children'] ?? [],
        ];
    }

    // Exclude headers & footers
    $excludedComponents = array_unique(array_merge(
        array_keys($availableHeaders),
        array_keys($availableFooters)
    ));

    $availableComponents = array_filter(
        $availableComponents,
        fn ($c, $name) => !in_array($name, $excludedComponents, true),
        ARRAY_FILTER_USE_BOTH
    );

    ksort($availableComponents);
}

// ----------------------------
// The one rich-text field
// ----------------------------
// What it starts with: the rich text already in the body, so a type switched to
// this editor keeps what an editor wrote before the switch.
$richTextLabel = '';
$richTextValue = '';

if ($richTextEditor !== null) {
    $richTextLabel = (string) (content_component_definition($richTextEditor['component'])['label'] ?? $richTextEditor['component']);

    foreach ($components as $component) {
        if (!is_array($component)) {
            continue;
        }

        $props  = is_array($component['props'] ?? null) ? $component['props'] : [];
        $schema = content_component_definition((string) ($component['type'] ?? ''))['schema'] ?? [];

        // The declared component wins; any other component still holding rich
        // text is the fallback for a type whose rich-text component changed.
        if (($component['type'] ?? '') === $richTextEditor['component']
            || ($schema[$richTextEditor['field']]['type'] ?? '') === 'quill') {
            $richTextValue = (string) ($props[$richTextEditor['field']] ?? '');
            break;
        }
    }
}

// ----------------------------
// Render
// ----------------------------
// Editor libraries are vendored locally (no CDN, no build step) and must
// execute before the editor module at the bottom of the page. Sortable powers
// drag-and-drop in the Add component editor, so it comes with the palette;
// Quill comes with a rich-text field, whether that is a component's or the
// content type's only field.
if ($richTextEditor === null) {
    $pageScripts[] = ['src' => 'admin/assets/vendor/sortable/Sortable.min.js'];
}

$needsQuill = $richTextEditor !== null;

foreach ($availableComponents as $availableComponent) {
    foreach ($availableComponent['schema'] as $field) {
        if (($field['type'] ?? '') === 'quill') {
            $needsQuill = true;
            break 2;
        }
    }
}

if ($needsQuill) {
    $pageStyles[]  = ['href' => 'admin/assets/vendor/quill/quill.snow.css'];
    $pageScripts[] = ['src' => 'admin/assets/vendor/quill/quill.js'];
}

ob_start();
?>
<div class="page-header">
    <div class="page-title">        
        <?php if ($isEdit): ?>
            <h2><?= e(admin_trans('editor_editing', ['type' => $typeLabel, 'title' => $title])) ?></h2>
        <?php else: ?>
            <h2><?= e(admin_trans('editor_create', ['type' => $typeLabel])) ?></h2>
        <?php endif; ?>
    </div>

    <div class="page-actions">
        <?php if ($isEdit): ?>
            <a class="no-underline mr-md"
                href="<?= url($slug === $homepageSlug ? '' : $url) ?>"
                target="_blank">
                <?= e(admin_trans('editor_visit', ['type' => $typeLabel])) ?>
            </a>

            <a class="btn-small btn-preview mr-md"
                href="<?= e(preview_url(url($slug === $homepageSlug ? '' : $url))) ?>"
                target="_blank"
                title="<?= e(admin_trans('editor_preview_title')) ?>">
                <?= e(admin_trans('common_preview')) ?>
            </a>

            <?php $historyCount = count_content_versions((int) $contentData['id']); ?>
            <a class="btn-small mr-md"
                href="<?= url('admin/content/versions') ?>?type=<?= urlencode($type) ?>&id=<?= (int) $contentData['id'] ?>"
                title="<?= e(admin_trans('versions_help')) ?>">
                <?= e(admin_trans('versions_history')) ?> (<?= (int) $historyCount ?>)
            </a>
        <?php endif; ?>

        <button type="submit" form="save">
            <?= e(admin_trans('editor_save', ['type' => $typeLabel])) ?>
        </button>
    </div>
</div>

<?php if ($restoringAutosave): ?>
    <div class="notice notice-info autosave-notice">
        <div class="autosave-notice-text">
            <p><strong><?= e(admin_trans('editor_autosave_viewing_title')) ?></strong></p>
            <p><?= e(admin_trans('editor_autosave_viewing')) ?></p>
        </div>
        <div class="autosave-notice-actions">
            <form method="post" class="inline-form js-confirm-form"
                  data-confirm-title="<?= e(admin_trans('editor_autosave_dismiss')) ?>"
                  data-confirm="<?= e(admin_trans('editor_autosave_discard_confirm')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="discard_autosave">
                <input type="hidden" name="version_id" value="<?= (int) $restoringAutosave['id'] ?>">
                <button type="submit" class="btn-small btn-muted"><?= e(admin_trans('editor_autosave_dismiss')) ?></button>
            </form>
        </div>
    </div>
<?php elseif ($pendingAutosave): ?>
    <div class="notice notice-info autosave-notice">
        <div class="autosave-notice-text">
            <p><strong><?= e(admin_trans('editor_autosave_found_title')) ?></strong></p>
            <p><?= e(admin_trans('editor_autosave_found', ['time' => format_local_datetime((int) $pendingAutosave['created_at'], 'Y-m-d H:i')])) ?></p>
        </div>
        <div class="autosave-notice-actions">
            <a class="btn-small btn-primary" href="<?= e(url('admin/content/edit') . '?type=' . urlencode($type) . '&id=' . (int) $contentData['id'] . '&restore_version=' . (int) $pendingAutosave['id']) ?>"><?= e(admin_trans('editor_autosave_review')) ?></a>

            <form method="post" class="inline-form js-confirm-form"
                  data-confirm-title="<?= e(admin_trans('editor_autosave_dismiss')) ?>"
                  data-confirm="<?= e(admin_trans('editor_autosave_discard_confirm')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="discard_autosave">
                <input type="hidden" name="version_id" value="<?= (int) $pendingAutosave['id'] ?>">
                <button type="submit" class="btn-small btn-muted"><?= e(admin_trans('editor_autosave_dismiss')) ?></button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php
/* What a crawler and a social card will show, resolved from seo_metadata() so
   an editor sees the fallbacks the form cannot show (site title, site
   description, default social image). The sidebar renders both previews and
   admin/assets/content-editor.js keeps them in step as the fields are typed. */
$previewPage = [
    'id'           => $contentData['id'] ?? 0,
    'type'         => $type,
    'title'        => (string) ($contentData['title'] ?? ''),
    'status'       => (string) ($contentData['status'] ?? 'draft'),
    'path'         => trim((string) ($url ?? ''), '/'),
    'meta'         => $meta,
    'published_at' => $contentData['published_at'] ?? null,
    'updated_at'   => $contentData['updated_at'] ?? null,
];

$seoPreview    = seo_metadata($previewPage);
$seoPreviewUrl = $seoPreview['canonical'];

// The URL an unsaved item will get: everything before the slug.
$seoPreviewBase = $isEdit
    ? substr($url, 0, max(0, strlen($url) - strlen($fullSlug)))
    : '/' . ($prefix !== '' ? $prefix . '/' : '');

$seoSettings = load_settings();

// Both previews are driven from the same resolved values, so they carry the
// same data attributes and differ only in what they show.
$seoPreviewAttrs = 'data-seo-preview'
    . ' data-url-base="' . e($seoPreviewBase) . '"'
    . ' data-site-url="' . e(site_origin()) . '"'
    . ' data-site-title="' . e($seoPreview['site_name']) . '"'
    . ' data-title-suffix="' . e((string) ($seoSettings['seo_title_suffix'] ?? '')) . '"'
    . ' data-home="' . ((int) ($contentData['id'] ?? 0) > 0 && (int) ($contentData['id'] ?? 0) === (int) ($seoSettings['homepage_id'] ?? 0) ? '1' : '') . '"'
    . ' data-default-description="' . e($seoPreview['description']) . '"'
    . ' data-default-image="' . e($seoPreview['og_image']) . '"'
    . ' data-no-image="' . e(admin_trans('editor_seo_preview_no_image')) . '"'
    . ' data-canonical="' . e((string) ($meta['canonical'] ?? '')) . '"';
?>

<form class="flex flex-row content-editor-form gap-lg" id="save" method="post" action="<?= url('admin/content/save') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$contentData['id'] ?>">
    <?php endif; ?>

    <!-- The two columns, with the SEO field cards in the main column and the
         previews of what they produce in the sidebar. -->
    <div class="editor-columns">

    <!-- What the editor is working on: the body card, the content type's own
         fields and its images. -->
    <div class="editor-main">

    <!-- The body: one rich-text card, or the component list. -->
    <?php if ($richTextEditor !== null): ?>
        <!-- This content type is written, not assembled: one rich text field,
             no component list and nothing to add. The field is created from
             #quill-editor-template by admin/assets/content-editor.js, which
             names the input and fills in the saved HTML. -->
        <fieldset class="card rich-text-container">
            <legend><?= e($richTextLabel) ?></legend>
            <div id="rich-text-container"></div>
        </fieldset>
    <?php else: ?>
        <!-- Components -->
        <fieldset class="card components-container">
            <legend><?= e(admin_trans('common_components')) ?></legend>
            <div id="components-container" class="">
                <!-- Adding is a wide target under the last component, not a drag from
                     a name-only list: the dialog has room for what a component is for
                     and what it looks like. It is the container's last child, so the
                     editor appends above it. -->
                <button type="button" class="component-add-zone" data-modal="component-picker">
                    <?= icon('plus', 22, 'component-add-icon') ?>
                    <span class="component-add-title"><?= e(admin_trans('editor_add_component')) ?></span>
                    <span class="component-add-help"><?= e(admin_trans('editor_add_component_help')) ?></span>
                </button>
            </div>
        </fieldset>
    <?php endif; ?>

    <!-- Content-type meta fields declared by the theme -->
    <?php if ($ctMetaFields): ?>
        <fieldset class="card">
            <legend><?= e(admin_trans('editor_details')) ?></legend>

            <div class="seo-fields">
                <?php foreach ($ctMetaFields as $metaFieldKey => $metaField): ?>
                    <?php
                    $metaFieldValue = content_meta_field_value($metaField, $meta[$metaFieldKey] ?? '');

                    include CMS_PATH . '/admin/partials/content-meta-field.php';
                    ?>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php endif; ?>

    <!-- Presentation images declared by the content type -->
    <?php $ctImageFields = is_array($ctConfig['images'] ?? null) ? $ctConfig['images'] : []; ?>
    <?php if ($ctImageFields): ?>
        <fieldset class="card">
            <legend><?= e(admin_trans('editor_images')) ?></legend>

            <div class="seo-fields">
                <?php foreach ($ctImageFields as $imageKey => $imageField): ?>
                    <?php
                    $imageLabel = (string) ($imageField['label'] ?? $imageKey);
                    $imageValue = $meta[$imageKey] ?? '';

                    if (!empty($imageField['multiple'])) {
                        $imageValues = array_values(array_filter(
                            is_array($imageValue) ? $imageValue : [$imageValue],
                            static fn($item): bool => (string) $item !== ''
                        ));
                        $imageInputName = 'meta_' . $imageKey . '[]';
                    }
                    ?>

                    <?php if (empty($imageField['multiple'])): ?>
                        <label class="field" for="meta-<?= e($imageKey) ?>">
                            <span class="field-label"><?= e($imageLabel) ?></span>
                            <div class="image-picker-wrapper">
                                <input class="field-input" type="text" id="meta-<?= e($imageKey) ?>"
                                    name="meta_<?= e($imageKey) ?>" value="<?= e((string) $imageValue) ?>" data-image-picker>
                                <img class="image-preview" alt="<?= e(admin_trans('media_no_image')) ?>">
                                <div class="image-picker-actions">
                                    <button type="button" class="select-image-btn"><?= e(admin_trans('media_select_image')) ?></button>
                                    <button type="button" class="clear-image-btn"><?= e(admin_trans('common_clear')) ?></button>
                                </div>
                            </div>
                        </label>
                    <?php else: ?>
                        <div class="field">
                            <span class="field-label"><?= e($imageLabel) ?></span>
                            <div class="gallery-rows" id="gallery-<?= e($imageKey) ?>" data-gallery-name="<?= e($imageInputName) ?>">
                                <!-- Sentinel row: removing every image still submits the field, so it can be cleared. -->
                                <input type="hidden" name="<?= e($imageInputName) ?>" value="">
                                <?php foreach ($imageValues as $imageRow): ?>
                                    <div class="gallery-row">
                                        <div class="image-picker-wrapper">
                                            <input class="field-input" type="text"
                                                name="<?= e($imageInputName) ?>" value="<?= e((string) $imageRow) ?>" data-image-picker>
                                            <img class="image-preview" alt="<?= e(admin_trans('media_no_image')) ?>">
                                            <div class="image-picker-actions">
                                                <!-- This row's Remove control is the image's clear action. -->
                                                <button type="button" class="select-image-btn"><?= e(admin_trans('media_select_image')) ?></button>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-small btn-muted remove-gallery-image"><?= e(admin_trans('editor_gallery_remove')) ?></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-small btn-secondary add-gallery-image" data-gallery="gallery-<?= e($imageKey) ?>"><?= e(admin_trans('editor_gallery_add')) ?></button>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php endif; ?>

    <!-- SEO & social fields, in the cards seo_editable_field_groups() names.
         What they produce is previewed in the sidebar. -->
    <?php foreach (seo_editable_field_groups() as $seoGroup): ?>
        <fieldset class="card">
            <legend><?= e($seoGroup['label']) ?></legend>

            <div class="seo-fields">
                <?php foreach ($seoGroup['fields'] as $key => $field): ?>
                    <?php
                    $fieldValue = (string) ($meta[$key] ?? '');
                    $inputName  = 'meta_' . $key;
                    $fieldId    = 'seo-' . $key;
                    ?>
                    <label class="field" for="<?= e($fieldId) ?>">
                        <span class="field-label"><?= e($field['label']) ?></span>

                        <?php if (($field['type'] ?? 'text') === 'textarea'): ?>
                            <textarea class="field-input" id="<?= e($fieldId) ?>" name="<?= e($inputName) ?>"
                                rows="2" <?= !empty($field['max']) ? 'maxlength="' . (int) $field['max'] . '"' : '' ?>
                            ><?= e($fieldValue) ?></textarea>
                        <?php elseif (($field['type'] ?? 'text') === 'media'): ?>
                            <div class="image-picker-wrapper">
                                <input class="field-input" type="text" id="<?= e($fieldId) ?>" name="<?= e($inputName) ?>"
                                    value="<?= e($fieldValue) ?>" data-image-picker>
                                <img class="image-preview" alt="<?= e(admin_trans('media_no_image')) ?>">
                                <div class="image-picker-actions">
                                    <button type="button" class="select-image-btn"><?= e(admin_trans('media_select_image')) ?></button>
                                    <button type="button" class="clear-image-btn"><?= e(admin_trans('common_clear')) ?></button>
                                </div>
                            </div>
                        <?php else: ?>
                            <input class="field-input" type="text" id="<?= e($fieldId) ?>" name="<?= e($inputName) ?>"
                                value="<?= e($fieldValue) ?>"
                                <?= !empty($field['max']) ? 'maxlength="' . (int) $field['max'] . '"' : '' ?>>
                        <?php endif; ?>

                        <?php if (!empty($field['help'])): ?>
                            <small><?= e($field['help']) ?></small>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php endforeach; ?>

    </div>
    <!-- /editor-main -->

    <!-- Sidebar -->
    <div id="sidebar-container" class="sidebar-container">

        <!-- Content Info -->
        <fieldset class="card">
            <legend><?= e($typeLabel) ?> <?= e(admin_trans('editor_info')) ?></legend>

            <label>
                <?= e(admin_trans('content_title')) ?>:
                <input type="text" id="title" name="title" value="<?= e($title) ?>" required>
            </label>

            <label>
                <?= e(admin_trans('common_slug')) ?>:
                <input type="text" id="slug" name="slug" value="<?= e($slug) ?>">
            </label>

            <label>
                <?= e(admin_trans('editor_category')) ?>
                <select name="category_id">
                    <option value=""><?= e(admin_trans('common_none')) ?></option>

                    <?php foreach ($categories as $cat): ?>
                        <option
                            value="<?= (int)$cat['id'] ?>"
                            <?= $selectedCategoryId == $cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?= e(admin_trans('nav_tags')) ?>
                <select name="tag_ids[]" multiple size="6">
                    <?php foreach ($tags as $tag): ?>
                        <option
                            value="<?= (int)$tag['id'] ?>"
                            <?= in_array($tag['id'], $selectedTagIds) ? 'selected' : '' ?>>
                            <?= e($tag['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small><?= e(admin_trans('editor_hold_ctrl')) ?></small>
            </label>

            <label>
                <?= e(admin_trans('editor_parent')) ?>:
                <select name="parent_id">
                    <option value=""><?= e(admin_trans('editor_no_parent')) ?></option>
                    <?php foreach ($parentOptions as $p): ?>
                        <option
                            value="<?= (int) $p['id'] ?>"
                            <?= ($currentParentId === $p['id'] && $p['id'] !== null) ? 'selected' : '' ?>
                        >
                            <?= e($p['title']) ?> (<?= e($p['slug']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?= e(admin_trans('common_status')) ?>:
                <select name="status">
                    <?php foreach (content_statuses() as $statusOption): ?>
                        <?php
                        // Publishing is an editorial capability; authors may
                        // save drafts only. The server enforces this again.
                        $statusAllowed = $statusOption !== 'published' || admin_can('content.publish');

                        if (!$statusAllowed && $statusOption !== $status) {
                            continue;
                        }
                        ?>
                        <option value="<?= e($statusOption) ?>" <?= $status === $statusOption ? 'selected' : '' ?>>
                            <?= e(admin_trans('status_' . $statusOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!admin_can('content.publish')): ?>
                    <small><?= e(admin_trans('editor_author_cannot_publish')) ?></small>
                <?php endif; ?>
            </label>

            <label id="scheduled-container">
                <?= e(admin_trans('editor_scheduled_publish')) ?>:
                <input 
                    type="datetime-local" 
                    name="scheduled_at" 
                    value="<?= $scheduledDate ?>"
                >
                <small><?= e(admin_trans('editor_schedule_help')) ?></small>
            </label>

        </fieldset>

        <!-- Layout -->
        <fieldset class="card">
            <legend><?= e(admin_trans('editor_layout_theme')) ?></legend>

            <label>
                <?= e(admin_trans('editor_layout')) ?>:
                <select name="layout">
                    <?php foreach ($availableLayouts as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $val === $pageLayout ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?= e(admin_trans('editor_header')) ?>:
                <select name="header">
                    <?php foreach ($availableHeaders as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $val === $pageHeader ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?= e(admin_trans('editor_footer')) ?>:
                <select name="footer">
                    <?php foreach ($availableFooters as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $val === $pageFooter ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </fieldset>

        <?php if ($publishChecklist): ?>
            <!-- Pre-publish checklist -->
            <fieldset class="card">
                <legend><?= e(admin_trans('checklist_title')) ?></legend>
                <ul class="checklist">
                    <?php foreach ($publishChecklist as $item): ?>
                        <?php $state = $item['ok'] ? 'ok' : ($item['level'] === 'block' ? 'block' : 'warn'); ?>
                        <li class="checklist-item" data-state="<?= e($state) ?>">
                            <span class="checklist-mark" aria-hidden="true"><?= $item['ok'] ? '&#10003;' : '!' ?></span>
                            <span>
                                <?= e(admin_trans('checklist_rule_' . $item['rule'])) ?>
                                <?php if (!$item['ok'] && $item['detail'] !== ''): ?>
                                    <small><?= e($item['detail']) ?></small>
                                <?php endif; ?>
                                <?php if (!$item['ok'] && $item['level'] === 'block'): ?>
                                    <small><?= e(admin_trans('checklist_blocks')) ?></small>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </fieldset>
        <?php endif; ?>

        <!-- What the SEO fields produce, beside the editor rather than in it. -->
        <fieldset class="card">
            <legend><?= e(admin_trans('editor_seo_preview_search_card')) ?></legend>

            <div class="seo-preview" <?= $seoPreviewAttrs ?>>
                <div class="seo-snippet">
                    <span class="seo-snippet-url" data-preview-url><?= e((string) preg_replace('#^https?://#', '', $seoPreviewUrl)) ?></span>
                    <span class="seo-snippet-title" data-preview-title><?= e($seoPreview['title']) ?></span>
                    <span class="seo-snippet-text" data-preview-description><?= e($seoPreview['description']) ?></span>
                </div>
            </div>
        </fieldset>

        <fieldset class="card">
            <legend><?= e(admin_trans('editor_seo_preview_social_card')) ?></legend>

            <div class="seo-preview" <?= $seoPreviewAttrs ?>>
                <div class="seo-card">
                    <div class="seo-card-image" data-preview-image>
                        <?php if ($seoPreview['og_image'] !== ''): ?>
                            <img src="<?= e($seoPreview['og_image']) ?>" alt="">
                        <?php else: ?>
                            <span class="seo-card-image-empty"><?= e(admin_trans('editor_seo_preview_no_image')) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="seo-card-body">
                        <span class="seo-card-domain" data-preview-domain><?= e((string) parse_url($seoPreviewUrl, PHP_URL_HOST)) ?></span>
                        <span class="seo-card-title" data-preview-card-title><?= e($seoPreview['og_title']) ?></span>
                        <span class="seo-card-text" data-preview-card-description><?= e($seoPreview['og_description']) ?></span>
                    </div>
                </div>
            </div>
        </fieldset>

        </div>
        <!-- /sidebar-container -->

    </div>
    <!-- /editor-columns -->

</form>

<script>
window.availableComponents = <?= json_encode($availableComponents) ?>;
window.initialComponents   = <?= json_encode($components) ?>;
<?php /* The single field: which input the editor fills in, and what it holds. */ ?>
window.richTextField = <?= $richTextEditor === null ? 'null' : json_encode([
    'component' => $richTextEditor['component'],
    'name'      => 'components[0][props][' . $richTextEditor['field'] . ']',
    'value'     => $richTextValue,
]) ?>;
window.mediaImages = <?= json_encode($mediaImagesJs, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>;
window.csrfToken = <?= json_encode(csrf_token()) ?>;
</script>

<?php include CMS_PATH . '/admin/partials/image-picker.php'; ?>
<?php if ($richTextEditor === null) include CMS_PATH . '/admin/partials/component-picker.php'; ?>
<?php include CMS_PATH . '/admin/partials/icon-picker.php'; ?>
<?php include CMS_PATH . '/admin/partials/content-editor-templates.php'; ?>
<script type="module" src="<?= admin_asset('admin/assets/content-editor.js') ?>"></script>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('editor_title')) ?></h3>
<p><?= e(admin_trans('editor_help')) ?></p>
<ul>
    <li><?= e(admin_trans('editor_help_components')) ?></li>
    <li><?= e(admin_trans('editor_help_status')) ?></li>
    <li><?= e(admin_trans('editor_help_preview')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'editor', 'section' => 'drafts-scheduling-and-preview'];

include CMS_PATH . '/admin/partials/layout.php';


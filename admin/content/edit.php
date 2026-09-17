<?php

$pageTitle = 'Content Editor';

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
    $contentData = load_content_by_id((int)$id);

    if (!$contentData) {
        redirect_with_toast(
            "content/?type={$type}",
            'error',
            "{$typeLabel} not found."
        );
    }

    $slug = $contentData['slug']; // keep old $slug variable for form display
}

// if there is contentdata, we're editing existing page
$isEdit = !empty($contentData);

// ----------------------------
// Content values
// ----------------------------
$title           = $contentData['title'] ?? '';
$status          = $contentData['status'] ?? 'draft';
$metaDescription = $contentData['meta']['description'] ?? '';
$components      = $contentData['body'] ?? [];

// SEO panel: keep the existing meta array and open the panel when it has content.
$meta = is_array($contentData['meta'] ?? null) ? $contentData['meta'] : [];
$seoHasValues = (bool) array_intersect(array_keys(seo_editable_fields()), array_keys($meta));

$scheduledDate = '';
if (!empty($contentData['scheduled_at'])) {
    $dt = new DateTime(
        '@' . (int) $contentData['scheduled_at'] // force UTC
    );
    $dt->setTimezone(new DateTimeZone(SITE_TIMEZONE));

    $scheduledDate = $dt->format('Y-m-d\TH:i');
}

// parent stuff
$allItems = list_content($type);

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
$allowedComponents = $ctConfig['available_components'] ?? [];

$coreComponentFiles = glob(CORE_PATH . '/components/*.php');
$themeComponentFiles = glob(CMS_PATH . '/theme/components/*.php');
$componentFiles = array_merge($coreComponentFiles, $themeComponentFiles);

$availableComponents = [];

foreach ($componentFiles as $file) {
    $name = basename($file, '.php');

    if (!empty($allowedComponents) && !in_array($name, $allowedComponents, true)) {
        continue;
    }

    $component = require $file;

    $availableComponents[$name] = [
        'label'            => $component['label'] ?? $name,
        'schema'           => $component['schema'] ?? [],
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

// ----------------------------
// Render
// ----------------------------
// Editor libraries are vendored locally (no CDN, no build step). They must
// execute before the editor module at the bottom of the page.
$pageStyles[]  = ['href' => 'admin/assets/vendor/quill/quill.snow.css'];
$pageScripts[] = ['src' => 'admin/assets/vendor/quill/quill.js'];
$pageScripts[] = ['src' => 'admin/assets/vendor/sortable/Sortable.min.js'];

ob_start();
?>
<div class="page-header">
    <div class="page-title">        
        <?php if ($isEdit): ?>
            <h2>Editing <?= e($typeLabel) ?>: <?= e($title) ?></h2>
        <?php else: ?>
            <h2>Create new <?= e($typeLabel) ?></h2>
        <?php endif; ?>
    </div>

    <div class="page-actions">
        <?php if ($isEdit): ?>
            <a class="no-underline mr-md"
                href="<?= url($slug === $settings['homepage_slug'] ? '' : $url) ?>"
                target="_blank">
                Visit <?= e($typeLabel) ?>
            </a>

            <a class="btn-small btn-preview mr-md"
                href="<?= e(preview_url(url($slug === $settings['homepage_slug'] ? '' : $url))) ?>"
                target="_blank"
                title="Renders live from the database, including unpublished changes">
                <?= e(admin_trans('preview')) ?>
            </a>

            <?php $historyCount = count_content_versions((int) $contentData['id']); ?>
            <a class="btn-small mr-md"
                href="<?= url('admin/content/versions') ?>?type=<?= urlencode($type) ?>&id=<?= (int) $contentData['id'] ?>"
                title="<?= e(admin_trans('version_history_help')) ?>">
                <?= e(admin_trans('history')) ?> (<?= (int) $historyCount ?>)
            </a>
        <?php endif; ?>

        <button type="submit" form="save">
            Save <?= e($typeLabel) ?>
        </button>
    </div>
</div>

<form class="flex flex-row gap-lg" id="save" method="post" action="<?= url('admin/content/save') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$contentData['id'] ?>">
    <?php endif; ?>

    <!-- Components -->
    <fieldset class="card components-container">
        <legend>Components</legend>
        <div id="components-container" class=""></div>
    </fieldset>

    <!-- Sidebar -->
    <div id="sidebar-container" class="sidebar-container">

        <!-- Content Info -->
        <fieldset class="card">
            <legend><?= e($typeLabel) ?> Info</legend>

            <label>
                Title:
                <input type="text" id="title" name="title" value="<?= e($title) ?>" required>
            </label>

            <label>
                Slug:
                <input type="text" id="slug" name="slug" value="<?= e($slug) ?>">
            </label>

            <label>
                Category
                <select name="category_id">
                    <option value="">— None —</option>

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
                Tags
                <select name="tag_ids[]" multiple size="6">
                    <?php foreach ($tags as $tag): ?>
                        <option
                            value="<?= (int)$tag['id'] ?>"
                            <?= in_array($tag['id'], $selectedTagIds) ? 'selected' : '' ?>>
                            <?= e($tag['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>Hold Ctrl/Cmd to select multiple</small>
            </label>

            <label>
                Parent:
                <select name="parent_id">
                    <option value="">— No parent (top level) —</option>
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
                Status:
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
                            <?= e(admin_trans($statusOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!admin_can('content.publish')): ?>
                    <small><?= e(admin_trans('author_cannot_publish')) ?></small>
                <?php endif; ?>
            </label>

            <label id="scheduled-container">
                Scheduled Publish:
                <input 
                    type="datetime-local" 
                    name="scheduled_at" 
                    value="<?= $scheduledDate ?>"
                >
                <small>Leave blank for immediate publishing</small>
            </label>

        </fieldset>

        <!-- Layout -->
        <fieldset class="card">
            <legend>Layout & Theme</legend>

            <label>
                Layout:
                <select name="layout">
                    <?php foreach ($availableLayouts as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $val === $pageLayout ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Header:
                <select name="header">
                    <?php foreach ($availableHeaders as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $val === $pageHeader ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Footer:
                <select name="footer">
                    <?php foreach ($availableFooters as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $val === $pageFooter ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </fieldset>

        <!-- SEO & social -->
        <fieldset class="card">
            <legend><?= e(admin_trans('seo_social')) ?></legend>

            <details <?= $seoHasValues ? 'open' : '' ?>>
                <summary><?= e(admin_trans('seo_social_help')) ?></summary>

                <div class="seo-fields">
                    <?php foreach (seo_editable_fields() as $key => $field): ?>
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
            </details>
        </fieldset>

        <!-- Component list -->
        <fieldset class="card">
          <legend>Component List</legend>
            <div id="component-palette">
                <?php foreach (array_keys($availableComponents) as $name): ?>
                    <div class="draggable-component" draggable="true" data-type="<?= e($name) ?>">
                        <?= e($availableComponents[$name]['label']) ?>
                    </div>
            <?php endforeach; ?>
            </div>
        </fieldset>

    </div>

</form>

<script>
window.availableComponents = <?= json_encode($availableComponents) ?>;
window.initialComponents   = <?= json_encode($components) ?>;
window.mediaImages = <?= json_encode($mediaImagesJs, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>;
window.csrfToken = <?= json_encode(csrf_token()) ?>;
</script>

<?php include CMS_PATH . '/admin/partials/image-picker.php'; ?>
<?php include CMS_PATH . '/admin/partials/content-editor-templates.php'; ?>
<script type="module" src="<?= admin_asset('admin/assets/content-editor.js') ?>"></script>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('content_editor')) ?></h3>
<p><?= e(admin_trans('editor_help')) ?></p>
<ul>
    <li><?= e(admin_trans('editor_components_help')) ?></li>
    <li><?= e(admin_trans('editor_status_help')) ?></li>
    <li><?= e(admin_trans('editor_preview_help')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'editor', 'section' => 'drafts-scheduling-and-preview'];

include CMS_PATH . '/admin/partials/layout.php';


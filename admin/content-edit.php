<?php

$pageTitle = 'Content Editor';
$username  = $_SESSION['user_id'] ?? 'User';

// ----------------------------
// Determine mode
// ----------------------------
$type = $_GET['type'] ?? 'page';
$slug = $_GET['slug'] ?? '';
$isEdit = $slug !== '';

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

if ($isEdit) {
    $contentData = load_content($type, $slug);

    if (!$contentData) {
        redirect_with_toast(
            "content-list?type={$type}",
            'error',
            "{$typeLabel} not found."
        );
    }
}

// ----------------------------
// Content values
// ----------------------------
$title           = $contentData['title'] ?? '';
$status          = $contentData['status'] ?? 'draft';
$metaDescription = $contentData['meta']['description'] ?? '';
$components      = $contentData['body'] ?? [];

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
ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>

        <?php if ($isEdit): ?>
            <p>Editing <?= e($typeLabel) ?>: <strong><?= e($title) ?></strong></p>
        <?php else: ?>
            <p>Create new <?= e($typeLabel) ?></p>
        <?php endif; ?>
    </div>

    <div class="page-actions">
        <?php if ($isEdit): ?>
            <a style="color:inherit; margin-right:2rem;" href="<?= url($slug === $settings['homepage_slug'] ? '' : $url) ?>" target="_blank">
                Visit <?= e($typeLabel) ?>
            </a>
        <?php endif; ?>

        <button type="submit" form="save">
            Save <?= e($typeLabel) ?>
        </button>
    </div>
</div>

<form id="save" method="post" action="<?= url('admin/content-save') ?>">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="original_slug" value="<?= e($slug) ?>">
    <?php endif; ?>

    <!-- Content Info -->
    <fieldset>
        <legend><?= e($typeLabel) ?> Info</legend>

        <label>
            Title:
            <input type="text" name="title" value="<?= e($title) ?>" required>
        </label>

        <label>
            Slug:
            <input type="text" name="slug" value="<?= e($slug) ?>">
        </label>

        <label>
            Meta Description:
            <textarea name="meta_description"><?= e($metaDescription) ?></textarea>
        </label>
        <label>
            Status:
            <select name="status">
                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
            </select>
        </label>
    </fieldset>

    <!-- Layout -->
    <fieldset>
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

    <!-- Components -->
    <div id="components-container"></div>

    <label>
        Add component:
        <select id="new-component-select">
            <option value="">-- Select Component--</option>
            <?php foreach (array_keys($availableComponents) as $name): ?>
                <option value="<?= e($name) ?>"><?= e($availableComponents[$name]['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="button" id="add-component">Add</button>
</form>

<script>
window.availableComponents = <?= json_encode($availableComponents) ?>;
window.initialComponents   = <?= json_encode($components) ?>;
window.contentType         = '<?= e($type) ?>';
</script>

<?php include __DIR__ . '/partials/content-editor-templates.php'; ?>
<script type="module" src="<?= url('admin/assets/content-editor.js') ?>"></script>

<?php
$content = ob_get_clean();
include __DIR__ . '/partials/layout.php';
<?php

$pageTitle = 'Edit Page';
$username = $_SESSION['user_id'] ?? 'User';

// ----------------------------
// Get slug
// ----------------------------
$slug = $_GET['slug'] ?? '';
if (!$slug) {
    redirect_with_toast('page-list', 'error', 'Edit - Missing page slug.');
}

// Load page JSON
$pageData = load_page($slug);
if (!$pageData) {
    redirect_with_toast('page-list', 'error', 'Page not found.');
}

// Load page values
$title = $pageData['title'] ?? '';
$metaDescription = $pageData['meta']['description'] ?? '';
$components = $pageData['components'] ?? [];

// Load available layouts / headers / footers
$theme = theme_config();
$settings = load_settings();

$availableLayouts = $theme['layouts'] ?? [];
$availableHeaders = $theme['headers'] ?? [];
$availableFooters = $theme['footers'] ?? [];

// Current page values or fallbacks
$pageLayout = $pageData['layout'] ?? $settings['default_layout'] ?? $theme['defaults']['layout'];
$pageHeader = $pageData['header'] ?? $settings['default_header'] ?? $theme['defaults']['header'];
$pageFooter = $pageData['footer'] ?? $settings['default_footer'] ?? $theme['defaults']['footer'];

// ----------------------------
// Load available components & schemas
// ----------------------------
$componentFiles = glob(CMS_PATH . '/theme/components/*.php');
$availableComponents = [];

foreach ($componentFiles as $file) {
    $name = basename($file, '.php');
    $component = require $file;

    // Build proper JS structure for editor
    $availableComponents[$name] = [
        'schema' => $component['schema'] ?? [],
        'children' => $component['children'] ?? 'any',
        'allowed_children' => $component['allowed_children'] ?? [],
    ];
}

// Collect component names used as headers or footers
$excludedComponents = array_unique(array_merge(
    array_keys($availableHeaders),
    array_keys($availableFooters)
));

// Exclude header/footer components from normal component list
$availableComponents = array_filter(
    $availableComponents,
    fn($c, $name) => !in_array($name, $excludedComponents, true),
    ARRAY_FILTER_USE_BOTH
);

ksort($availableComponents);

// ----------------------------
// Render page
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Welcome, <?= e($username) ?> 👋</h2>
        <p>Editing page: <strong><?= e($title) ?></strong></p>
    </div>
    <div class="page-actions">
        <a style="color:inherit; margin-right:2rem;" href="<?= url($slug == e($settings['homepage_slug']) ? '' : $slug) ?>" target="_blank">Visit Page</a>
        <button type="submit" form="save">Save Page</button>
    </div>
</div>

<form id="save" method="post" action="<?= url('admin/page-save') ?>">
    

    <!-- Page Info -->
    <fieldset>
        <legend>Page Info</legend>
        <label>
            Title:
            <input type="text" id="title" name="title" value="<?= e($title) ?>" required>
        </label>

        <label>
            Slug:
            <input type="text" id="slug" name="slug" value="<?= e($slug) ?>">
        </label>

        <label>
            Meta Description:
            <textarea name="meta_description"><?= e($metaDescription) ?></textarea>
        </label>

    </fieldset>
    
    <!-- Layout and Theme -->
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
    <div id="components-container">
    </div>

    <!-- Add top-level component -->
    <label>
        Select top-level component to add:
        <select id="new-component-select">
            <option value="">-- Select Component --</option>
            <?php foreach (array_keys($availableComponents) as $name): ?>
                <option value="<?= e($name) ?>"><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="button" id="add-component" class="add-component-btn">Add Component</button>
</form>

<script>
window.availableComponents = <?= json_encode($availableComponents) ?>;
window.initialComponents   = <?= json_encode($components) ?>;
</script>
<?php include __DIR__ . '/partials/page-editor-templates.php'; ?>
<script type="module" src="<?= url('core/admin/assets/page-editor.js') ?>"></script>

<?php
$content = ob_get_clean();
include __DIR__ . '/partials/layout.php';
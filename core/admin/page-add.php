<?php

$pageTitle = 'Add Page';
$username = $_SESSION['user_id'] ?? 'User';

// Start with empty page
$title = '';
$metaDescription = '';
$components = [];

// Optional: validate query param 'slug' if passed, otherwise rely on save
$slug = $_GET['slug'] ?? '';
if ($slug && !preg_match('/^[a-z0-9_-]+$/', $slug)) {
    redirect_with_toast('page-list', 'error', 'Invalid slug in URL.');
}

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

// Exclude site-header/footer
$excluded = ['site-header','site-footer'];
$availableComponents = array_filter(
    $availableComponents,
    fn($c, $name) => !in_array($name, $excluded),
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
        <p>Create a new page</p>
    </div>
    <div class="page-actions">
        <button type="submit" form="save"><?= e('Save Page') ?></button>
    </div>
</div>

<form id="save" method="post" action="<?= url('admin/page-save') ?>">
    <!-- Page Info -->
    <fieldset>
        <legend>Page Info</legend>
        <label>
            Title:
            <input type="text" name="title" id="title" value="<?= e($title) ?>" required>
        </label>
        <label>
            Slug:
            <input type="text" id="slug" name="slug" value="">
        </label>
        <label>
            Meta Description:
            <textarea name="meta_description"><?= e($metaDescription) ?></textarea>
        </label>
    </fieldset>

    <!-- Components -->
    <div id="components-container"></div>

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
</script>
<?php include __DIR__ . '/partials/page-editor-templates.php'; ?>
<script type="module" src="<?= url('core/admin/assets/page-editor.js') ?>"></script>

<?php
$content = ob_get_clean();
include __DIR__ . '/partials/layout.php';
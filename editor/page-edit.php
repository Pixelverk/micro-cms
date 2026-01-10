<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

$pageTitle = 'Edit Page';
$username = $_SESSION['user_id'] ?? 'User';

// ----------------------------
// Get slug
// ----------------------------
$slug = $_GET['slug'] ?? '';
if (!$slug) {
    redirect_with_toast('page-list.php', 'error', 'Edit - Missing page slug.');
}

// Load page JSON
$pageData = load_page($slug);
if (!$pageData) {
    redirect_with_toast('page-list.php', 'error', 'Page not found.');
}

// Load page values
$title = $pageData['title'] ?? '';
$metaDescription = $pageData['meta']['description'] ?? '';
$components = $pageData['components'] ?? [];

// ----------------------------
// Load available components & schemas
// ----------------------------
$componentFiles = glob(__DIR__ . '/../_components/*/body.php');
$availableComponents = [];

foreach ($componentFiles as $file) {
    $name = basename(dirname($file));
    $component = require $file;
    $availableComponents[$name] = $component['schema'] ?? [];
}

// Exclude site-header/footer
$excluded = ['site-header','site-footer'];
$availableComponents = array_filter($availableComponents, fn($schema, $name) => !in_array($name, $excluded), ARRAY_FILTER_USE_BOTH);
ksort($availableComponents);

// ----------------------------
// Recursive function to render component fieldsets
// ----------------------------
function renderComponentFieldset(array $comp, array $availableComponents): string {
    $type = $comp['type'] ?? '';
    $schema = $availableComponents[$type] ?? [];
    $path = $comp['path'] ?? '';

    ob_start();
    ?>
    <fieldset class="component" data-path="<?= e($path) ?>">
        <legend><?= e($type) ?></legend>

        <input type="hidden" name="components[<?= e($path) ?>][type]" value="<?= e($type) ?>">

        <?php foreach ($schema as $name => $field): 
            $value = $comp['props'][$name] ?? $field['default'] ?? '';
        ?>
            <label>
                <?= e($field['label'] ?? $name) ?>:
                <?php if (($field['type'] ?? 'string') === 'textarea'): ?>
                    <textarea name="components[<?= e($path) ?>][props][<?= e($name) ?>]"><?= e($value) ?></textarea>
                <?php else: ?>
                    <input type="text" name="components[<?= e($path) ?>][props][<?= e($name) ?>]" value="<?= e($value) ?>">
                <?php endif; ?>
            </label>
        <?php endforeach; ?>

        <!-- Children -->
        <div class="children-container">
            <?php foreach ($comp['children'] ?? [] as $child): ?>
                <?= renderComponentFieldset($child, $availableComponents) ?>
            <?php endforeach; ?>
        </div>

        <!-- Actions -->
        <div class="component-actions">
            <button type="button" class="add-child-btn">Add Child Component</button>
            <button type="button" class="remove-btn">×</button>
        </div>
    </fieldset>
    <?php
    return ob_get_clean();
}

// ----------------------------
// Assign paths to components recursively
// ----------------------------
function assignPaths(array &$comps, string $prefix = '') {
    foreach ($comps as $i => &$comp) {
        $path = $prefix === '' ? (string)$i : $prefix . '-' . $i;
        $comp['path'] = $path;
        if (!empty($comp['children'])) {
            assignPaths($comp['children'], $path);
        }
    }
}
assignPaths($components);

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
        <a style="color:inherit; margin-right:2rem;" href="<?= url($slug) ?>" target="_blank">Visit Page</a>
        <button type="submit" form="save">Save Page</button>
    </div>
</div>

<form id="save" method="post" action="<?= url('editor/page-save.php') ?>">
    

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

    <!-- Components -->
    <div id="components-container">
        <?php foreach ($components as $comp): ?>
            <?= renderComponentFieldset($comp, $availableComponents) ?>
        <?php endforeach; ?>
    </div>

    <!-- Add top-level component -->
    <label>
        Select component to add:
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
<script type="module" src="<?= url('editor/_assets/page-editor.js') ?>"></script>

<?php
$content = ob_get_clean();
include __DIR__ . '/_partials/layout.php';
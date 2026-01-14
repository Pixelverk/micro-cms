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
$componentFiles = glob(__DIR__ . '/../_components/*/body.php');
$availableComponents = [];

foreach ($componentFiles as $file) {
    $name = basename(dirname($file));
    $component = require $file;

    // Build proper JS structure
    $availableComponents[$name] = [
        'schema' => $component['schema'] ?? [],
        'children' => $component['children'] ?? 'any',
        'allowed_children' => $component['allowed_children'] ?? []
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
        <p>Editing page: <strong><?= e($title) ?></strong></p>
    </div>
    <div class="page-actions">
        <a style="color:inherit; margin-right:2rem;" href="<?= url($slug ==  e(get_setting('homepage_slug')) ? '' : $slug) ?>" target="_blank">Visit Page</a>
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
window.initialComponents = <?= json_encode($components) ?>;
</script>
<?php include __DIR__ . '/_partials/page-editor-templates.php'; ?>
<script type="module" src="<?= url('editor/_assets/page-editor.js') ?>"></script>

<?php
$content = ob_get_clean();
include __DIR__ . '/_partials/layout.php';
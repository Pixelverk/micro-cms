<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

$pageTitle = 'Add Page';
$username = $_SESSION['user_id'] ?? 'User';

// Load available components from root _components folder
$componentFiles = glob(__DIR__ . '/../_components/*.js');
$availableComponents = array_map(fn($f) => basename($f, '.js'), $componentFiles);

// Exclude site-header/footer
$excluded = ['site-header','site-footer'];
$availableComponents = array_filter($availableComponents, fn($c) => !in_array($c, $excluded));
sort($availableComponents);

// Start with an empty page
$title = '';
$metaDescription = '';
$components = [];

// Optional: validate query param 'slug' if passed, otherwise rely on save
$slug = $_GET['slug'] ?? '';
if ($slug && !preg_match('/^[a-z0-9_-]+$/', $slug)) {
    redirect_with_toast('page-list.php', 'error', 'Invalid slug in URL.');
}

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Welcome, <?= htmlspecialchars($username) ?> 👋</h2>
        <p>Create a new page</p>
    </div>
    <div class="page-actions">
        <button type="submit" form="create">Create Page</button>
    </div>
</div>

<form id="create" method="post" action="page-save.php">
    <label>
        Slug (URL-friendly name):
        <input type="text" name="slug" required>
    </label>

    <!-- Page Info -->
    <fieldset>
        <legend>Page Info</legend>
        <label>
            Title:
            <input type="text" name="title" value="<?= htmlspecialchars($title) ?>" required>
        </label>

        <label>
            Meta Description:
            <textarea name="meta_description"><?= htmlspecialchars($metaDescription) ?></textarea>
        </label>
    </fieldset>

    <!-- Components container -->
    <div id="components-container">
        <!-- Empty initially -->
    </div>

    <!-- Add Top-Level Component -->
    <label>
        Select component to add:
        <select id="new-component-select">
            <option value="">-- Select Component --</option>
            <?php foreach ($availableComponents as $compName): ?>
                <option value="<?= htmlspecialchars($compName) ?>"><?= htmlspecialchars($compName) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="button" id="add-component" class="add-component-btn">Add Component</button>
</form>

<script>
    window.availableComponents = <?= json_encode(array_values($availableComponents)) ?>;
</script>
<script type="module" src="./_assets/page-editor.js"></script>

<?php
$content = ob_get_clean();
include __DIR__ . '/_partials/layout.php';
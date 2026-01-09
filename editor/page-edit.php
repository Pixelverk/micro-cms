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
    redirect_with_toast('page-list.php', 'error', 'Missing page slug.');
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

// Load available components from root _components folder
$componentFiles = glob(__DIR__ . '/../_components/*.js');
$availableComponents = array_map(fn($f) => basename($f, '.js'), $componentFiles);

// Exclude site-header/footer
$excluded = ['site-header','site-footer'];
$availableComponents = array_filter($availableComponents, fn($c) => !in_array($c, $excluded));
sort($availableComponents);

// Recursive function to render existing components
function renderComponentFieldset(array $comp, array $availableComponents): string {
    ob_start();
    ?>
    <fieldset class="component" data-path="<?= htmlspecialchars($comp['path'] ?? '') ?>">
        <legend><?= htmlspecialchars($comp['type']) ?></legend>       

        <input type="hidden" name="components[<?= htmlspecialchars($comp['path'] ?? '') ?>][type]" value="<?= htmlspecialchars($comp['type']) ?>">

        <?php foreach ($comp['props'] ?? [] as $name => $value): ?>
            <label>
                <?= htmlspecialchars($name) ?>:
                <input type="text" name="components[<?= htmlspecialchars($comp['path'] ?? '') ?>][props][<?= htmlspecialchars($name) ?>]" value="<?= htmlspecialchars($value) ?>">
            </label>
        <?php endforeach; ?>

        <!-- Children container -->
        <div class="children-container">
            <?php foreach ($comp['children'] ?? [] as $child): ?>
                <?= renderComponentFieldset($child, $availableComponents) ?>
            <?php endforeach; ?>
        </div>

        <!-- Add Child and remove component Buttons -->
        <div class="component-actions">
            <button type="button" class="add-child-btn">Add Child Component</button>
            <button type="button" class="remove-btn">×</button>
        </div>
    </fieldset>
    <?php
    return ob_get_clean();
}

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Welcome, <?php echo htmlspecialchars($username); ?> 👋</h2>
        <p>Editing page: <strong><?= htmlspecialchars($slug) ?></strong></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="save">Save Page</button>
    </div>
</div>

<form id="save" method="post" action="page-save.php" id="page-form">
    <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">

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

    <!-- Existing Components -->
    <div id="components-container">
        <?php
        $componentIndex = 0;
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

        foreach ($components as $comp) {
            echo renderComponentFieldset($comp, $availableComponents);
        }
        ?>
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
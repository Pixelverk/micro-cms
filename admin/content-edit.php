<?php

$pageTitle = 'Content Editor';
$username  = $_SESSION['user_id'] ?? 'User';

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
            "content-list?type={$type}",
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

$scheduledDate = '';
if (!empty($contentData['scheduled_at'])) {
    $dt = new DateTime(
        '@' . (int) $contentData['scheduled_at'] // force UTC
    );
    $dt->setTimezone(new DateTimeZone(SITE_TIMEZONE));

    $scheduledDate = $dt->format('Y-m-d\TH:i');
}

// parent stuff
if ($isEdit) {
    $allItems = list_content($type); // get all items of this type
    $fullSlug = build_full_slug($contentData, $allItems);
    $url = '/' . ($prefix ? $prefix . '/' : '') . $fullSlug;
}

function get_descendant_ids(int $id, array $allItems): array {
    $descendants = [];
    foreach ($allItems as $item) {
        if (($item['parent_id'] ?? null) === $id) {
            $descendants[] = $item['id'];
            $descendants = array_merge($descendants, get_descendant_ids($item['id'], $allItems));
        }
    }
    return $descendants;
}

// Parent options
$allParents = list_content($type);
$currentId = $contentData['id'] ?? null;
$currentParentId = $contentData['parent_id'] ?? null;

// exclude self and descendants from parent options
$excludeIds = $currentId ? array_merge([$currentId], get_descendant_ids($currentId, $allParents)) : [];
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
        <?php if ($isEdit): ?>
            <h2>Editing <?= e($typeLabel) ?>: <?= e($title) ?></h2>
        <?php else: ?>
            <h2>Create new <?= e($typeLabel) ?></h2>
        <?php endif; ?>
    </div>

    <div class="page-actions">
        <?php if ($isEdit): ?>
            <a style="color:inherit; margin-right:2rem;" 
                href="<?= url($slug === $settings['homepage_slug'] ? '' : $url) ?>" 
                target="_blank">
                Visit <?= e($typeLabel) ?>
            </a>
        <?php endif; ?>

        <button type="submit" form="save">
            Save <?= e($typeLabel) ?>
        </button>
    </div>
</div>

<form class="flex flex-row gap-lg" id="save" method="post" action="<?= url('admin/content-save') ?>">
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
                Meta Description:
                <textarea name="meta_description"><?= e($metaDescription) ?></textarea>
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
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
                    <option value="scheduled" <?= $status === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                </select>
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

        <!-- Component list -->
        <fieldset class="card">
            <legend>Component Lists</legend>
            <label>
                All components:
                <select id="new-component-select">
                    <option value="">-- Select Component--</option>
                    <?php foreach (array_keys($availableComponents) as $name): ?>
                        <option value="<?= e($name) ?>"><?= e($availableComponents[$name]['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="button" id="add-component">Add</button>
        </fieldset>

    </div>

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
<?php
// admin/category/edit.php

$pageTitle = 'Edit Category';
$username  = current_username();

$theme = theme_config();
$contentTypes = $theme['content_types'] ?? [];

$pdo = db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

$category = [
    'name'        => '',
    'slug'        => '',
    'description' => '',
];

// ----------------------------
// Load existing
// ----------------------------
if ($id) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM taxonomy
        WHERE id = ? AND taxonomy_type = 'category'
    ");
    $stmt->execute([$id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        redirect_with_toast('category', 'error', 'Category not found.');
    }

    $category = $row;
    $pageTitle = admin_trans('edit_category') . ': ' . $category['name'];
}

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e($id ? admin_trans('edit_category') : admin_trans('add_category_title')) ?></h2>
    </div>
</div>

<form method="post" action="<?= url('admin/category/save') ?>" class="form-card">
    <?= csrf_field() ?>

    <?php if ($id): ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php endif; ?>

    <label>
        <strong><?= e(admin_trans('name')) ?></strong>
        <input
            type="text"
            name="name"
            id="name"
            required
            value="<?= e($category['name']) ?>"
            placeholder="News, Tutorials, Updates…"
        >
    </label>

    <label>
        <strong><?= e(admin_trans('slug')) ?></strong>
        <input
            type="text"
            name="slug"
            id="slug"
            value="<?= e($category['slug']) ?>"
            placeholder="news"
        >
        <small><?= e(admin_trans('used_in_urls')) ?></small>
    </label>

    <label>
        <strong><?= e(admin_trans('content_type')) ?></strong>
        <select name="content_type" required>
            <?php foreach ($contentTypes as $key => $config): ?>
                <option value="<?= e($key) ?>"
                    <?= ($category['content_type'] ?? '') === $key ? 'selected' : '' ?>>
                    <?= e($config['label'] ?? ucfirst($key)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <strong><?= e(admin_trans('description')) ?></strong>
        <textarea
            name="description"
            rows="4"
            placeholder="<?= e(admin_trans('optional_description')) ?>"
        ><?= e($category['description']) ?></textarea>
    </label>

    <div class="form-actions">
        <button class="btn-primary">
            <?= e($id ? admin_trans('save_changes') : admin_trans('create_category')) ?>
        </button>

        <a href="<?= url('admin/category') ?>" class="btn-secondary">
            <?= e(admin_trans('cancel')) ?>
        </a>
    </div>

</form>

<script>
// ----------------------------
// Auto slug from name
// ----------------------------
const nameInput = document.getElementById('name');
const slugInput = document.getElementById('slug');

function slugify(str) {
    return str
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

// only auto-fill if user hasn't typed manually
let slugTouched = false;
slugInput.addEventListener('input', () => slugTouched = true);

nameInput.addEventListener('input', () => {
    if (!slugTouched) {
        slugInput.value = slugify(nameInput.value);
    }
});
</script>

<?php
$content = ob_get_clean();

// ----------------------------
// Help panel
// ----------------------------
ob_start();
?>
<h3>Category editor</h3>
<p>Create or update a category.</p>
<ul>
    <li><strong>Name</strong> is displayed to users</li>
    <li><strong>Slug</strong> becomes the URL identifier</li>
    <li><strong>Description</strong> is optional metadata</li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';
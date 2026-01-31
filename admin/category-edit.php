<?php
// admin/category-edit.php

$pageTitle = 'Edit Category';
$username  = $_SESSION['user_id'] ?? 'User';

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
        redirect_with_toast('category-list', 'error', 'Category not found.');
    }

    $category = $row;
    $pageTitle = 'Edit Category: ' . $category['name'];
}

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= $id ? 'Edit Category' : 'Add Category' ?></h2>
    </div>
</div>

<form method="post" action="<?= url('admin/category-save') ?>" class="form-card">

    <?php if ($id): ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php endif; ?>

    <label>
        <strong>Name</strong>
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
        <strong>Slug</strong>
        <input
            type="text"
            name="slug"
            id="slug"
            value="<?= e($category['slug']) ?>"
            placeholder="news"
        >
        <small>Used in URLs. Leave empty to auto-generate.</small>
    </label>

    <label>
        <strong>Content Type</strong>
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
        <strong>Description</strong>
        <textarea
            name="description"
            rows="4"
            placeholder="Optional description…"
        ><?= e($category['description']) ?></textarea>
    </label>

    <div class="form-actions">
        <button class="btn-primary">
            <?= $id ? 'Save Changes' : 'Create Category' ?>
        </button>

        <a href="<?= url('admin/category-list') ?>" class="btn-secondary">
            Cancel
        </a>
    </div>

</form>

<style>
.form-card {
    max-width: 600px;
    display:flex;
    flex-direction:column;
    gap:1rem;
}

.form-card input,
.form-card textarea {
    width:100%;
}

.form-actions {
    margin-top:1rem;
    display:flex;
    gap:1rem;
}
</style>

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

include __DIR__ . '/partials/layout.php';
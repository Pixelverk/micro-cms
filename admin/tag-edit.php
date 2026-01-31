<?php
// admin/tag-edit.php

$pageTitle = 'Edit Tag';
$username  = $_SESSION['user_id'] ?? 'User';

$pdo = db();

// ----------------------------
// Theme / content types
// ----------------------------
$theme = theme_config();
$contentTypes = $theme['content_types'] ?? [];

// ----------------------------
// Load existing (edit mode)
// ----------------------------
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

$tag = [
    'name'         => '',
    'slug'         => '',
    'description'  => '',
    'content_type' => '',
];

if ($id) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM taxonomy
        WHERE id = ?
        AND taxonomy_type = 'tag'
    ");
    $stmt->execute([$id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        redirect_with_toast('tag-list', 'error', 'Tag not found.');
    }

    $tag = $row;
    $pageTitle = 'Edit Tag: ' . $tag['name'];
}

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= $id ? 'Edit Tag' : 'Add Tag' ?></h2>
    </div>
</div>

<form method="post" action="<?= url('admin/tag-save') ?>" class="form-card">

    <?php if ($id): ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php endif; ?>

    <!-- Name -->
    <label>
        <strong>Name</strong>
        <input
            type="text"
            name="name"
            id="name"
            required
            value="<?= e($tag['name']) ?>"
            placeholder="featured, design, tips…"
        >
    </label>

    <!-- Slug -->
    <label>
        <strong>Slug</strong>
        <input
            type="text"
            name="slug"
            id="slug"
            value="<?= e($tag['slug']) ?>"
            placeholder="featured"
        >
        <small>Used in URLs. Leave empty to auto-generate.</small>
    </label>

    <!-- Content type -->
    <label>
        <strong>Content Type</strong>
        <select name="content_type" required>
            <?php foreach ($contentTypes as $key => $config): ?>
                <option
                    value="<?= e($key) ?>"
                    <?= ($tag['content_type'] ?? '') === $key ? 'selected' : '' ?>>
                    <?= e($config['label'] ?? ucfirst($key)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <small>Tags apply only to this content type.</small>
    </label>

    <!-- Description -->
    <label>
        <strong>Description</strong>
        <textarea
            name="description"
            rows="4"
            placeholder="Optional description…"
        ><?= e($tag['description']) ?></textarea>
    </label>

    <!-- Actions -->
    <div class="form-actions">
        <button class="btn-primary">
            <?= $id ? 'Save Changes' : 'Create Tag' ?>
        </button>

        <a href="<?= url('admin/tag-list') ?>" class="btn-secondary">
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
.form-card textarea,
.form-card select {
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
<h3>Tag editor</h3>
<p>Tags are flexible labels you can assign to many content items.</p>
<ul>
    <li>Multiple tags can be assigned to a single item</li>
    <li>Great for filtering or grouping related content</li>
    <li>Tags are specific to one content type</li>
</ul>
<?php
$pageHelp = ob_get_clean();

include __DIR__ . '/partials/layout.php';
?>

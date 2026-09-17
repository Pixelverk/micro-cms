<?php
// admin/tag-edit.php

$pageTitle = 'Edit Tag';

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
        redirect_with_toast('tag', 'error', 'Tag not found.');
    }

    $tag = $row;
    $pageTitle = admin_trans('edit_tag') . ': ' . $tag['name'];
}

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e($id ? admin_trans('edit_tag') : admin_trans('add_tag_title')) ?></h2>
    </div>
</div>

<form method="post" action="<?= url('admin/tag/save') ?>" class="form-card">
    <?= csrf_field() ?>

    <?php if ($id): ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php endif; ?>

    <!-- Name -->
    <label>
        <strong><?= e(admin_trans('name')) ?></strong>
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
        <strong><?= e(admin_trans('slug')) ?></strong>
        <input
            type="text"
            name="slug"
            id="slug"
            value="<?= e($tag['slug']) ?>"
            placeholder="featured"
        >
        <small><?= e(admin_trans('used_in_urls')) ?></small>
    </label>

    <!-- Content type -->
    <label>
        <strong><?= e(admin_trans('content_type')) ?></strong>
        <select name="content_type" required>
            <?php foreach ($contentTypes as $key => $config): ?>
                <option
                    value="<?= e($key) ?>"
                    <?= ($tag['content_type'] ?? '') === $key ? 'selected' : '' ?>>
                    <?= e($config['label'] ?? ucfirst($key)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <small><?= e(admin_trans('tags_content_type_help')) ?></small>
    </label>

    <!-- Description -->
    <label>
        <strong><?= e(admin_trans('description')) ?></strong>
        <textarea
            name="description"
            rows="4"
            placeholder="<?= e(admin_trans('optional_description')) ?>"
        ><?= e($tag['description']) ?></textarea>
    </label>

    <!-- Actions -->
    <div class="form-actions">
        <button class="btn-primary">
            <?= e($id ? admin_trans('save_changes') : admin_trans('create_tag')) ?>
        </button>

        <a href="<?= url('admin/tag') ?>" class="btn-secondary">
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

include CMS_PATH . '/admin/partials/layout.php';
?>

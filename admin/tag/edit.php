<?php
// admin/tag-edit.php

$pageTitle = admin_trans('tag_edit');

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
        redirect_with_toast('tag', 'error', admin_trans('tag_error_not_found'));
    }

    $tag = $row;
    $pageTitle = admin_trans('tag_edit') . ': ' . $tag['name'];
}

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e($id ? admin_trans('tag_edit') : admin_trans('tag_add_title')) ?></h2>
    </div>
</div>

<form method="post" action="<?= url('admin/tag/save') ?>" class="form-card">
    <?= csrf_field() ?>

    <?php if ($id): ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php endif; ?>

    <!-- Name -->
    <label>
        <strong><?= e(admin_trans('common_name')) ?></strong>
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
        <strong><?= e(admin_trans('common_slug')) ?></strong>
        <input
            type="text"
            name="slug"
            id="slug"
            value="<?= e($tag['slug']) ?>"
            placeholder="featured"
        >
        <small><?= e(admin_trans('common_used_in_urls')) ?></small>
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
        <small><?= e(admin_trans('tag_content_type_help')) ?></small>
    </label>

    <!-- Description -->
    <label>
        <strong><?= e(admin_trans('common_description')) ?></strong>
        <textarea
            name="description"
            rows="4"
            placeholder="<?= e(admin_trans('common_optional_description')) ?>"
        ><?= e($tag['description']) ?></textarea>
    </label>

    <!-- Actions -->
    <div class="form-actions">
        <button class="btn-primary">
            <?= e($id ? admin_trans('common_save') : admin_trans('tag_create')) ?>
        </button>

        <a href="<?= url('admin/tag') ?>" class="btn-secondary">
            <?= e(admin_trans('common_cancel')) ?>
        </a>
    </div>

</form>

<?php
$content = ob_get_clean();


// ----------------------------
// Help panel
// ----------------------------
ob_start();
?>
<h3><?= e(admin_trans('tag_help_title')) ?></h3>
<p><?= e(admin_trans('tag_help')) ?></p>
<ul>
    <li><?= e(admin_trans('tag_help_many')) ?></li>
    <li><?= e(admin_trans('tag_help_filter')) ?></li>
    <li><?= e(admin_trans('tag_content_type_help')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';
?>

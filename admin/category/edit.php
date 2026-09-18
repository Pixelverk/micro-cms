<?php
// admin/category/edit.php

$pageTitle = admin_trans('category_edit');

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
        redirect_with_toast('category', 'error', admin_trans('category_error_not_found'));
    }

    $category = $row;
    $pageTitle = admin_trans('category_edit') . ': ' . $category['name'];
}

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e($id ? admin_trans('category_edit') : admin_trans('category_add_title')) ?></h2>
    </div>
</div>

<form method="post" action="<?= url('admin/category/save') ?>">
    <?= csrf_field() ?>

    <?php if ($id): ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php endif; ?>

    <fieldset class="settings-group">
        <legend>
            <?= icon('bookmark-book', 18) ?>
            <?= e(admin_trans('common_details')) ?>
        </legend>

        <div class="field-grid field-grid-3 card">
            <div class="field">
                <label class="field-label" for="name"><?= e(admin_trans('common_name')) ?></label>
                <input
                    class="field-input"
                    type="text"
                    id="name"
                    name="name"
                    required
                    value="<?= e($category['name']) ?>"
                    placeholder="News, Tutorials, Updates…"
                >
            </div>

            <div class="field">
                <label class="field-label" for="slug"><?= e(admin_trans('common_slug')) ?></label>
                <input
                    class="field-input"
                    type="text"
                    id="slug"
                    name="slug"
                    value="<?= e($category['slug']) ?>"
                    placeholder="news"
                >
                <small><?= e(admin_trans('common_used_in_urls')) ?></small>
            </div>

            <div class="field">
                <label class="field-label" for="content_type"><?= e(admin_trans('content_type')) ?></label>
                <select class="field-input" id="content_type" name="content_type" required>
                    <?php foreach ($contentTypes as $key => $config): ?>
                        <option value="<?= e($key) ?>"
                            <?= ($category['content_type'] ?? '') === $key ? 'selected' : '' ?>>
                            <?= e($config['label'] ?? ucfirst($key)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field field-span">
                <label class="field-label" for="description"><?= e(admin_trans('common_description')) ?></label>
                <textarea
                    class="field-input"
                    id="description"
                    name="description"
                    rows="4"
                    placeholder="<?= e(admin_trans('common_optional_description')) ?>"
                ><?= e($category['description']) ?></textarea>
            </div>

        </div>
    </fieldset>

    <div class="form-actions">
        <button class="btn-primary">
            <?= e($id ? admin_trans('common_save') : admin_trans('category_create')) ?>
        </button>

        <a href="<?= url('admin/category') ?>" class="btn-secondary">
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
<h3><?= e(admin_trans('category_help_title')) ?></h3>
<p><?= e(admin_trans('category_help')) ?></p>
<ul>
    <li><strong><?= e(admin_trans('common_name')) ?></strong> <?= e(admin_trans('category_help_name')) ?></li>
    <li><strong><?= e(admin_trans('common_slug')) ?></strong> <?= e(admin_trans('category_help_slug')) ?></li>
    <li><strong><?= e(admin_trans('common_description')) ?></strong> <?= e(admin_trans('category_help_description')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';
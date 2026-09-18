<?php
// admin/category/index.php

$pageTitle = admin_trans('nav_categories');

$pdo = db();

// ----------------------------
// Search
// ----------------------------
$search = trim($_GET['q'] ?? '');

// ----------------------------
// Load categories
// ----------------------------
$sql = "SELECT * FROM taxonomy WHERE taxonomy_type = 'category'";
$params = [];

if ($search !== '') {
    $sql .= " AND name LIKE :q";
    $params['q'] = "%{$search}%";
}

$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('category_title')) ?></h2>
    </div>

    <div class="page-actions flex gap-md items-center">

        <!-- Search -->
        <form method="get" class="mr-md">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e(admin_trans('category_search')) ?>">
        </form>

        <!-- Add New -->
        <a href="<?= url('admin/category/edit') ?>" class="btn-primary">
            <?= e(admin_trans('category_add')) ?>
        </a>

    </div>
</div>

<?php if (empty($categories)): ?>
    <p><?= e(admin_trans('category_empty')) ?></p>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th><?= e(admin_trans('common_name')) ?></th>
                <th><?= e(admin_trans('common_slug')) ?></th>
                <th><?= e(admin_trans('common_description')) ?></th>
                <th><?= e(admin_trans('common_created')) ?></th>
                <th><?= e(admin_trans('common_updated')) ?></th>
                <th class="col-actions col-actions-icons"><?= e(admin_trans('common_actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $cat): ?>
            <tr>
                <td><?= e($cat['name']) ?></td>
                <td><code><?= e($cat['slug']) ?></code></td>
                <td><?= e($cat['description']) ?></td>
                <td><?= format_local_datetime($cat['created_at'], 'Y-m-d') ?></td>
                <td><?= format_local_datetime($cat['updated_at'], 'Y-m-d') ?></td>
                <td class="actions col-actions-icons">
                    <a href="<?= url('admin/category/edit') ?>?id=<?= (int)$cat['id'] ?>"
                       class="btn-small btn-icon"
                       title="<?= e(admin_trans('common_edit')) ?>"
                       aria-label="<?= e(admin_trans('common_edit')) ?>">
                        <?= icon('edit', 16) ?>
                    </a>

                    <form method="post" action="<?= url('admin/category/remove') ?>"
                        data-confirm-title="<?= e(admin_trans('category_delete')) ?>"
                        data-confirm="<?= e(admin_trans('category_delete_confirm', ['name' => $cat['name']])) ?>"
                        class="inline-form-block js-confirm-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                        <button type="submit" class="btn-delete btn-small btn-icon"
                                title="<?= e(admin_trans('common_delete')) ?>"
                                aria-label="<?= e(admin_trans('common_delete')) ?>">
                            <?= icon('trash', 16) ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
$content = ob_get_clean();

// ----------------------------
// Help panel
// ----------------------------
ob_start();
?>
<h3><?= e(admin_trans('category_list')) ?></h3>
<p><?= e(admin_trans('category_list_help')) ?></p>
<ul>
    <li><?= e(admin_trans('category_name_help')) ?></li>
    <li><?= e(admin_trans('category_slug_help')) ?></li>
    <li><?= e(admin_trans('category_description_help')) ?></li>
    <li><?= e(admin_trans('category_timestamps_help')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';

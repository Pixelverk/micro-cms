<?php
// admin/tag/index.php

$pageTitle = 'Tags';
$username  = current_username();

$pdo = db();

// ----------------------------
// Theme / content types
// ----------------------------
$theme = theme_config();
$contentTypes = $theme['content_types'] ?? [];

// filter by content type (optional)
$type = $_GET['type'] ?? '';

// search
$search = trim($_GET['q'] ?? '');

// ----------------------------
// Build query
// ----------------------------
$sql = "
    SELECT *
    FROM taxonomy
    WHERE taxonomy_type = 'tag'
";

$params = [];

if ($type !== '') {
    $sql .= " AND content_type = :type";
    $params['type'] = $type;
}

if ($search !== '') {
    $sql .= " AND name LIKE :q";
    $params['q'] = "%{$search}%";
}

$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Tags</h2>
        <p><?= e(admin_trans('welcome')) ?>, <?= e($username) ?> 👋</p>
    </div>

    <div class="page-actions flex gap-md items-center">

        <!-- Content type filter -->
        <form method="get">
            <select name="type" onchange="this.form.submit()">
                <option value=""><?= e(admin_trans('all_types')) ?></option>
                <?php foreach ($contentTypes as $key => $config): ?>
                    <option value="<?= e($key) ?>"
                        <?= $type === $key ? 'selected' : '' ?>>
                        <?= e($config['label'] ?? ucfirst($key)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <!-- Search -->
        <form method="get">
            <?php if ($type): ?>
                <input type="hidden" name="type" value="<?= e($type) ?>">
            <?php endif; ?>
            <input
                type="text"
                name="q"
                value="<?= e($search) ?>"
                placeholder="<?= e(admin_trans('search_tags')) ?>"
            >
        </form>

        <!-- Add -->
        <a href="<?= url('admin/tag/edit') ?>" class="btn-primary">
            <?= e(admin_trans('add_tag')) ?>
        </a>
    </div>
</div>

<?php if (!$tags): ?>
    <p><?= e(admin_trans('no_tags')) ?></p>
<?php else: ?>

<table class="content-table">
    <thead>
        <tr>
            <th><?= e(admin_trans('name')) ?></th>
            <th><?= e(admin_trans('slug')) ?></th>
            <th><?= e(admin_trans('content_type')) ?></th>
            <th><?= e(admin_trans('updated')) ?></th>
            <th style="width:160px;"><?= e(admin_trans('actions')) ?></th>
        </tr>
    </thead>

    <tbody>
    <?php foreach ($tags as $tag): ?>
        <tr>
            <td><?= e($tag['name']) ?></td>

            <td>
                <code><?= e($tag['slug']) ?></code>
            </td>

            <td>
                <?= e($contentTypes[$tag['content_type']]['label']
                    ?? ucfirst($tag['content_type'])) ?>
            </td>

            <td>
                <?= format_local_datetime($tag['updated_at'], 'Y-m-d') ?>
            </td>

            <td class="actions">

                <a href="<?= url('admin/tag/edit') ?>?id=<?= (int)$tag['id'] ?>"
                   class="btn-small">
                    <?= e(admin_trans('edit')) ?>
                </a>

                <form
                    action="<?= url('admin/tag/remove') ?>"
                    method="post"
                    class="js-confirm-form"
                    data-confirm-title="<?= e(admin_trans('delete_tag')) ?>"
                    data-confirm="<?= e(admin_trans('delete_tag_confirm', ['name' => $tag['name']])) ?>"
                    style="display:inline"
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$tag['id'] ?>">
                    <button type="submit" class="btn-delete btn-small">
                        <?= e(admin_trans('delete')) ?>
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
<h3><?= e(admin_trans('tags')) ?></h3>
<p><?= e(admin_trans('tag_list_help')) ?></p>
<ul>
    <li><?= e(admin_trans('tag_help_many')) ?></li>
    <li><?= e(admin_trans('tag_help_filter')) ?></li>
    <li>Tags are specific to a content type</li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';
?>
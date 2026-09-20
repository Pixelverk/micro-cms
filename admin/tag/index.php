<?php
// admin/tag/index.php

$pageTitle = admin_trans('nav_tags');

$pdo = db();

// ----------------------------
// Theme / content types
// ----------------------------
$theme = theme_config();
$contentTypes = $theme['content_types'] ?? [];

// filter by content type (optional)
$type = isset($contentTypes[$_GET['type'] ?? '']) ? (string) $_GET['type'] : '';

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

// Counts for the type tabs, taken before the type/search filters so each tab
// shows its own total.
$typeCounts = [];
$countStmt  = $pdo->prepare("SELECT COUNT(*) FROM taxonomy WHERE taxonomy_type = 'tag' AND content_type = ?");

foreach (array_keys($contentTypes) as $key) {
    $countStmt->execute([$key]);
    $typeCounts[$key] = (int) $countStmt->fetchColumn();
}

$totalCount = (int) $pdo->query("SELECT COUNT(*) FROM taxonomy WHERE taxonomy_type = 'tag'")->fetchColumn();

// URL that preserves the current filters while changing one of them.
$filterUrl = function (array $overrides = []) use ($type, $search): string {
    $query = array_filter(array_merge([
        'type' => $type,
        'q'    => $search,
    ], $overrides), fn($value) => $value !== '' && $value !== null);

    return url('admin/tag') . ($query ? '?' . http_build_query($query) : '');
};

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('tag_title')) ?></h2>
        <p><?= e(admin_trans('tag_intro')) ?></p>
    </div>

    <div class="page-actions">

        <!-- Add -->
        <a href="<?= url('admin/tag/edit') ?>" class="btn-primary">
            <?= icon('plus', 16) ?><?= e(admin_trans('tag_add')) ?>
        </a>
    </div>
</div>

<div class="content-filters">
    <div class="status-tabs">
        <a href="<?= e($filterUrl(['type' => ''])) ?>"
           class="status-tab <?= $type === '' ? 'active' : '' ?>">
            <?= e(admin_trans('content_type_all')) ?>
            <span class="status-tab-count"><?= (int) $totalCount ?></span>
        </a>
        <?php foreach ($contentTypes as $key => $config): ?>
            <a href="<?= e($filterUrl(['type' => $key])) ?>"
               class="status-tab <?= $type === $key ? 'active' : '' ?>">
                <?= e($config['label'] ?? ucfirst($key)) ?>
                <span class="status-tab-count"><?= (int) ($typeCounts[$key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="content-search">
        <?php if ($type !== ''): ?>
            <input type="hidden" name="type" value="<?= e($type) ?>">
        <?php endif; ?>
        <input type="search" name="q" value="<?= e($search) ?>"
               placeholder="<?= e(admin_trans('tag_search')) ?>" aria-label="<?= e(admin_trans('tag_search')) ?>">
        <?php if ($search !== ''): ?>
            <a href="<?= e($filterUrl(['q' => ''])) ?>" class="btn-small btn-muted"><?= e(admin_trans('common_clear')) ?></a>
        <?php endif; ?>
    </form>
</div>

<?php if (!$tags): ?>
    <div class="empty-state">
        <span class="empty-state-icon" aria-hidden="true"><?= icon('label', 24) ?></span>
        <p class="empty-state-title"><?= e(admin_trans('tag_empty')) ?></p>
    </div>
<?php else: ?>

<table class="content-table">
    <thead>
        <tr>
            <th><?= e(admin_trans('common_name')) ?></th>
            <th><?= e(admin_trans('common_slug')) ?></th>
            <th><?= e(admin_trans('content_type')) ?></th>
            <th><?= e(admin_trans('common_updated')) ?></th>
            <th class="col-actions col-actions-icons"><?= e(admin_trans('common_actions')) ?></th>
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

            <td class="actions col-actions-icons">

                <a href="<?= url('admin/tag/edit') ?>?id=<?= (int)$tag['id'] ?>"
                   class="btn-small btn-icon"
                   title="<?= e(admin_trans('common_edit')) ?>"
                   aria-label="<?= e(admin_trans('common_edit')) ?>">
                    <?= icon('edit', 16) ?>
                </a>

                <form
                    action="<?= url('admin/tag/remove') ?>"
                    method="post"
                    class="inline-form js-confirm-form"
                    data-confirm-title="<?= e(admin_trans('tag_delete')) ?>"
                    data-confirm="<?= e(admin_trans('tag_delete_confirm', ['name' => $tag['name']])) ?>"
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$tag['id'] ?>">
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
<h3><?= e(admin_trans('nav_tags')) ?></h3>
<p><?= e(admin_trans('tag_list_help')) ?></p>
<ul>
    <li><?= e(admin_trans('tag_help_many')) ?></li>
    <li><?= e(admin_trans('tag_help_filter')) ?></li>
    <li><?= e(admin_trans('tag_content_type_help')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';
?>
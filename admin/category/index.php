<?php
// admin/category/index.php

$pageTitle = admin_trans('nav_categories');

$theme = theme_config();
$contentTypes = $theme['content_types'] ?? [];

$pdo = db();

// ----------------------------
// Filters
// ----------------------------
$type   = isset($contentTypes[$_GET['type'] ?? '']) ? (string) $_GET['type'] : '';
$search = trim($_GET['q'] ?? '');

// ----------------------------
// Load categories
// ----------------------------
$sql = "SELECT * FROM taxonomy WHERE taxonomy_type = 'category'";
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
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for the type tabs, taken before the type/search filters so each tab
// shows its own total.
$typeCounts = [];
$countStmt  = $pdo->prepare("SELECT COUNT(*) FROM taxonomy WHERE taxonomy_type = 'category' AND content_type = ?");

foreach (array_keys($contentTypes) as $key) {
    $countStmt->execute([$key]);
    $typeCounts[$key] = (int) $countStmt->fetchColumn();
}

$totalCount = (int) $pdo->query("SELECT COUNT(*) FROM taxonomy WHERE taxonomy_type = 'category'")->fetchColumn();

// URL that preserves the current filters while changing one of them.
$filterUrl = function (array $overrides = []) use ($type, $search): string {
    $query = array_filter(array_merge([
        'type' => $type,
        'q'    => $search,
    ], $overrides), fn($value) => $value !== '' && $value !== null);

    return url('admin/category') . ($query ? '?' . http_build_query($query) : '');
};

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('category_title')) ?></h2>
        <p><?= e(admin_trans('category_intro')) ?></p>
    </div>

    <div class="page-actions">

        <!-- Add New -->
        <a href="<?= url('admin/category/edit') ?>" class="btn-primary">
            <?= icon('plus', 16) ?><?= e(admin_trans('category_add')) ?>
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
               placeholder="<?= e(admin_trans('category_search')) ?>" aria-label="<?= e(admin_trans('category_search')) ?>">
        <?php if ($search !== ''): ?>
            <a href="<?= e($filterUrl(['q' => ''])) ?>" class="btn-small btn-muted"><?= e(admin_trans('common_clear')) ?></a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($categories)): ?>
    <div class="empty-state">
        <span class="empty-state-icon" aria-hidden="true"><?= icon('bookmark-book', 24) ?></span>
        <p class="empty-state-title"><?= e(admin_trans('category_empty')) ?></p>
    </div>
<?php else: ?>
<?php /* Bulk actions live in their own form outside the table: every row
         already holds a delete form, and forms cannot nest. The row
         checkboxes join it with the form attribute. */ ?>
    <form id="bulk-form" method="post" action="<?= e(url('admin/category/bulk')) ?>" class="bulk-toolbar js-confirm-form" hidden
          data-confirm-title="<?= e(admin_trans('category_delete_selected')) ?>"
          data-confirm="<?= e(admin_trans('category_bulk_confirm')) ?>">
        <?= csrf_field() ?>

        <span class="bulk-count"><strong id="bulk-count">0</strong> <?= e(admin_trans('bulk_selected')) ?></span>

        <button type="submit" class="btn-small btn-danger"><?= e(admin_trans('category_delete_selected')) ?></button>
        <button type="button" class="btn-small btn-muted" id="bulk-clear"><?= e(admin_trans('bulk_clear_selection')) ?></button>
    </form>

    <table class="content-table">
        <thead>
            <tr>
                <th class="col-select">
                    <input type="checkbox" id="bulk-select-all" aria-label="<?= e(admin_trans('bulk_select_all')) ?>">
                </th>
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
                <td class="col-select">
                    <input type="checkbox" class="bulk-row" name="ids[]" value="<?= (int) $cat['id'] ?>"
                           form="bulk-form" aria-label="<?= e($cat['name']) ?>">
                </td>
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

<script>
/* Select-all and the bulk toolbar, mirroring the media library and the content list. */
(() => {
    const all = document.getElementById('bulk-select-all');
    if (!all) return;

    const boxes = Array.from(document.querySelectorAll('.bulk-row'));
    const toolbar = document.getElementById('bulk-form');
    const countEl = document.getElementById('bulk-count');
    const clearBtn = document.getElementById('bulk-clear');

    const selected = () => boxes.filter(box => box.checked);

    function sync() {
        const chosen = selected();

        toolbar.hidden = chosen.length === 0;
        countEl.textContent = chosen.length;

        all.checked = chosen.length > 0 && chosen.length === boxes.length;
        all.indeterminate = chosen.length > 0 && chosen.length < boxes.length;
    }

    boxes.forEach(box => box.addEventListener('change', sync));

    all.addEventListener('change', () => {
        boxes.forEach(box => { box.checked = all.checked; });
        sync();
    });

    clearBtn.addEventListener('click', () => {
        boxes.forEach(box => { box.checked = false; });
        all.checked = false;
        sync();
    });

    sync();
})();
</script>

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

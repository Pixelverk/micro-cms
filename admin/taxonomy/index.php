<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taxonomy terms
|--------------------------------------------------------------------------
| One list for every declared taxonomy: /admin/taxonomy?type=<name>. Category
| and Tag are the defaults; a theme may declare more. The old /admin/category
| and /admin/tag URLs are routed here by core/router.php.
|
| The content-type tabs remain until taxonomy terms stop carrying a content
| type; a term is currently scoped to one.
*/

$taxonomies = theme_taxonomies();

$type = (string) ($_GET['type'] ?? '');

if (!isset($taxonomies[$type])) {
    $type = (string) (array_key_first($taxonomies) ?? '');
}

if ($type === '' || !isset($taxonomies[$type])) {
    redirect_with_toast('dashboard', 'error', admin_trans('taxonomy_error_not_found', ['label' => 'Taxonomy']));
}

$typeLabel  = taxonomy_label($type);
$typeLabels = taxonomy_label($type, true);
$pageTitle  = $typeLabels;

// Which content types offer this taxonomy, for the indicator beside the search.
$usedBy = [];

foreach (theme_config()['content_types'] ?? [] as $ctKey => $ctConfig) {
    if (in_array($type, array_map('strval', (array) ($ctConfig['taxonomies'] ?? [])), true)) {
        $usedBy[] = (string) ($ctConfig['label'] ?? ucfirst((string) $ctKey));
    }
}

$pdo = db();

// ----------------------------
// Filters
// ----------------------------
$search = trim((string) ($_GET['q'] ?? ''));

// ----------------------------
// Load terms
// ----------------------------
$sql    = "SELECT * FROM taxonomy WHERE taxonomy_type = :taxonomy";
$params = ['taxonomy' => $type];

if ($search !== '') {
    $sql .= " AND name LIKE :q ESCAPE '\\'";
    $params['q'] = '%' . like_escape($search) . '%';
}

$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$terms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// URL that preserves the current filter while changing it.
$filterUrl = function (array $overrides = []) use ($type, $search): string {
    $query = array_filter(array_merge([
        'type' => $type,
        'q'    => $search,
    ], $overrides), fn($value) => $value !== '' && $value !== null);

    return url('admin/taxonomy') . ($query ? '?' . http_build_query($query) : '');
};

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e($typeLabels) ?></h2>
        <p><?= e(admin_trans('taxonomy_intro')) ?></p>
    </div>

    <div class="page-actions">
        <label class="flex items-center gap-sm mb-0">
            <span class="nowrap"><?= e(admin_trans('taxonomy_type')) ?>:</span>
            <select id="taxonomy-type-select">
                <?php foreach ($taxonomies as $name => $config): ?>
                    <option value="<?= e($name) ?>" <?= $name === $type ? 'selected' : '' ?>>
                        <?= e(taxonomy_label($name, true)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <a href="<?= url('admin/taxonomy/edit') . '?type=' . urlencode($type) ?>" class="btn-primary">
            <?= icon('plus', 16) ?><?= e(admin_trans('taxonomy_add', ['label' => $typeLabel])) ?>
        </a>
    </div>
</div>

<div class="content-filters">
    <div class="status-tabs">
        <span class="status-tab">
            <?= $usedBy
                ? e(admin_trans('taxonomy_used_by')) . ': ' . e(implode(', ', $usedBy))
                : e(admin_trans('taxonomy_used_by_none')) ?>
        </span>
    </div>

    <form method="get" class="content-search">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="search" name="q" value="<?= e($search) ?>"
               placeholder="<?= e(admin_trans('taxonomy_search', ['labels' => $typeLabels])) ?>"
               aria-label="<?= e(admin_trans('taxonomy_search', ['labels' => $typeLabels])) ?>">
        <?php if ($search !== ''): ?>
            <a href="<?= e($filterUrl(['q' => ''])) ?>" class="btn-small btn-muted"><?= e(admin_trans('common_clear')) ?></a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($terms)): ?>
    <div class="empty-state">
        <span class="empty-state-icon" aria-hidden="true"><?= icon('bookmark-book', 24) ?></span>
        <p class="empty-state-title"><?= e(admin_trans('taxonomy_empty', ['labels' => $typeLabels])) ?></p>
    </div>
<?php else: ?>
<?php /* Bulk actions live in their own form outside the table: every row
         already holds a delete form, and forms cannot nest. The row
         checkboxes join it with the form attribute. */ ?>
    <form id="bulk-form" method="post" action="<?= e(url('admin/taxonomy/bulk') . '?type=' . urlencode($type)) ?>" class="bulk-toolbar js-confirm-form" hidden
          data-confirm-title="<?= e(admin_trans('taxonomy_delete_selected', ['labels' => $typeLabels])) ?>"
          data-confirm="<?= e(admin_trans('taxonomy_bulk_confirm', ['labels' => $typeLabels, 'label' => $typeLabel])) ?>">
        <?= csrf_field() ?>

        <span class="bulk-count"><strong id="bulk-count">0</strong> <?= e(admin_trans('bulk_selected')) ?></span>

        <button type="submit" class="btn-small btn-danger"><?= e(admin_trans('taxonomy_delete_selected', ['labels' => $typeLabels])) ?></button>
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
        <?php foreach ($terms as $term): ?>
            <tr>
                <td class="col-select">
                    <input type="checkbox" class="bulk-row" name="ids[]" value="<?= (int) $term['id'] ?>"
                           form="bulk-form" aria-label="<?= e($term['name']) ?>">
                </td>
                <td><?= e($term['name']) ?></td>
                <td><code><?= e($term['slug']) ?></code></td>
                <td><?= e($term['description']) ?></td>
                <td><?= format_local_datetime($term['created_at'], 'Y-m-d') ?></td>
                <td><?= format_local_datetime($term['updated_at'], 'Y-m-d') ?></td>
                <td class="actions col-actions-icons">
                    <a href="<?= e(url('admin/taxonomy/edit') . '?type=' . urlencode($type) . '&id=' . (int) $term['id']) ?>"
                       class="btn-small btn-icon"
                       title="<?= e(admin_trans('common_edit')) ?>"
                       aria-label="<?= e(admin_trans('common_edit')) ?>">
                        <?= icon('edit', 16) ?>
                    </a>

                    <form method="post" action="<?= e(url('admin/taxonomy/remove') . '?type=' . urlencode($type)) ?>"
                        data-confirm-title="<?= e(admin_trans('taxonomy_delete', ['label' => $typeLabel])) ?>"
                        data-confirm="<?= e(admin_trans('taxonomy_delete_confirm', ['label' => $typeLabel, 'name' => $term['name']])) ?>"
                        class="inline-form-block js-confirm-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $term['id'] ?>">
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

/* The type selector switches which taxonomy the list shows. */
const taxonomyTypeSelect = document.getElementById('taxonomy-type-select');

if (taxonomyTypeSelect) {
    taxonomyTypeSelect.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('type', taxonomyTypeSelect.value);
        window.location.href = url.toString();
    });
}
</script>

<?php
$content = ob_get_clean();

// ----------------------------
// Help panel
// ----------------------------
ob_start();
?>
<h3><?= e(admin_trans('taxonomy_help_title', ['label' => $typeLabel])) ?></h3>
<p><?= e(admin_trans('taxonomy_list_help', ['labels' => $typeLabels])) ?></p>
<ul>
    <li><?= e(admin_trans('taxonomy_name_help')) ?></li>
    <li><?= e(admin_trans('taxonomy_slug_help')) ?></li>
    <li><?= e(admin_trans('taxonomy_description_help', ['label' => $typeLabel])) ?></li>
    <li><?= e(admin_trans('taxonomy_timestamps_help')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';

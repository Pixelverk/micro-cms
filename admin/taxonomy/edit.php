<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Add / edit a taxonomy term
|--------------------------------------------------------------------------
| Generic over the taxonomy named by ?type=. The content-type field stays
| until terms stop carrying a content type.
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
$pageTitle  = admin_trans('taxonomy_edit', ['label' => $typeLabel]);

$pdo = db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

$term = [
    'name'        => '',
    'slug'        => '',
    'description' => '',
];

// ----------------------------
// Load existing
// ----------------------------
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM taxonomy WHERE id = ? AND taxonomy_type = ?");
    $stmt->execute([$id, $type]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        redirect_with_toast('taxonomy', 'error', admin_trans('taxonomy_error_not_found', ['label' => $typeLabel]), ['type' => $type]);
    }

    $term = $row;
    $pageTitle = admin_trans('taxonomy_edit', ['label' => $typeLabel]) . ': ' . $term['name'];
}

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e($id ? admin_trans('taxonomy_edit', ['label' => $typeLabel]) : admin_trans('taxonomy_add_title', ['label' => $typeLabel])) ?></h2>
    </div>
</div>

<form method="post" action="<?= e(url('admin/taxonomy/save') . '?type=' . urlencode($type)) ?>">
    <?= csrf_field() ?>

    <?php if ($id): ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
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
                    value="<?= e($term['name']) ?>"
                >
            </div>

            <div class="field">
                <label class="field-label" for="slug"><?= e(admin_trans('common_slug')) ?></label>
                <input
                    class="field-input"
                    type="text"
                    id="slug"
                    name="slug"
                    value="<?= e($term['slug']) ?>"
                >
                <small><?= e(admin_trans('common_used_in_urls')) ?></small>
            </div>

            <div class="field field-span">
                <label class="field-label" for="description"><?= e(admin_trans('common_description')) ?></label>
                <textarea
                    class="field-input"
                    id="description"
                    name="description"
                    rows="4"
                    placeholder="<?= e(admin_trans('common_optional_description')) ?>"
                ><?= e($term['description']) ?></textarea>
            </div>

        </div>
    </fieldset>

    <div class="form-actions">
        <button class="btn-primary">
            <?= e($id ? admin_trans('common_save') : admin_trans('taxonomy_create', ['label' => $typeLabel])) ?>
        </button>

        <a href="<?= e(url('admin/taxonomy') . '?type=' . urlencode($type)) ?>" class="btn-secondary">
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
<h3><?= e(admin_trans('taxonomy_help_title', ['label' => $typeLabel])) ?></h3>
<p><?= e(admin_trans('taxonomy_help', ['label' => $typeLabel])) ?></p>
<ul>
    <li><strong><?= e(admin_trans('common_name')) ?></strong> <?= e(admin_trans('taxonomy_help_name')) ?></li>
    <li><strong><?= e(admin_trans('common_slug')) ?></strong> <?= e(admin_trans('taxonomy_help_slug')) ?></li>
    <li><strong><?= e(admin_trans('common_description')) ?></strong> <?= e(admin_trans('taxonomy_help_description')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Block library
|--------------------------------------------------------------------------
| Create, rename, inspect and delete reusable component blocks.
|--------------------------------------------------------------------------
*/

require_capability('content.create');

$pageTitle = admin_trans('block_library');

$editId = (int) ($_GET['id'] ?? 0);
$editing = $editId > 0 ? load_block($editId) : null;

if ($editId > 0 && !$editing) {
    redirect_with_toast('block', 'error', 'That block no longer exists.');
}

// ----------------------------
// Create / update
// ----------------------------
$errors = [];
$openEditor = $editing !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $blockId = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $tree = json_decode((string) ($_POST['tree'] ?? '[]'), true);

    $result = save_block([
        'label'        => $_POST['label'] ?? '',
        'description'  => $_POST['description'] ?? '',
        'tree'         => is_array($tree) ? $tree : [],
        'content_type' => $_POST['content_type'] ?? 'page',
    ], $blockId);

    if ($result['ok']) {
        redirect_with_toast('block', 'success', $blockId ? 'Block updated.' : 'Block created.');
    }

    $errors = $result['errors'] ?? ['tree' => 'Could not save that block.'];
    $openEditor = true;
}

// ----------------------------
// Delete
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $blockId = (int) ($_POST['id'] ?? 0);

    if (delete_block($blockId)) {
        redirect_with_toast('block', 'success', 'Block deleted.');
    }

    redirect_with_toast('block', 'error', 'That block no longer exists.');
}

$blocks = list_blocks();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('block_library')) ?></h2>
        <p><?= count($blocks) ?> <?= e(admin_trans('blocks_available')) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn-small btn-muted" href="<?= e(url('admin/content')) ?>"><?= e(admin_trans('content')) ?></a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="notice notice-error">
        <?php foreach ($errors as $message): ?>
            <p><?= e($message) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($editing || $openEditor): ?>
    <?php
    $editTree = $editing ? block_tree($editing) : [];
    ?>
    <form method="post" class="form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="tree" id="block-tree" value="<?= e(json_encode($editTree, JSON_UNESCAPED_SLASHES)) ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
        <?php endif; ?>

        <fieldset>
            <legend><?= $editing ? e(admin_trans('edit_block')) : e(admin_trans('new_block')) ?></legend>

            <label class="field">
                <span class="field-label"><?= e(admin_trans('label')) ?></span>
                <input class="field-input" type="text" name="label" required maxlength="80"
                       value="<?= e($editing['label'] ?? '') ?>">
            </label>

            <label class="field">
                <span class="field-label"><?= e(admin_trans('description')) ?></span>
                <input class="field-input" type="text" name="description" maxlength="200"
                       value="<?= e($editing['description'] ?? '') ?>">
            </label>

            <label class="field">
                <span class="field-label"><?= e(admin_trans('components')) ?></span>
                <textarea class="field-input" id="block-tree-input" rows="6"
                          placeholder='[{"type":"cta-section","props":{...},"children":[]}]'><?= e(json_encode($editTree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                <small><?= e(admin_trans('block_tree_help')) ?></small>
            </label>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn-primary"><?= e(admin_trans('save_changes')) ?></button>
            <a class="btn-small btn-muted" href="<?= e(url('admin/block')) ?>"><?= e(admin_trans('cancel')) ?></a>
        </div>
    </form>
<?php endif; ?>

<?php if (empty($blocks)): ?>
    <p class="empty-state"><?= e(admin_trans('no_blocks')) ?></p>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th><?= e(admin_trans('label')) ?></th>
                <th><?= e(admin_trans('slug')) ?></th>
                <th><?= e(admin_trans('components')) ?></th>
                <th><?= e(admin_trans('author')) ?></th>
                <th><?= e(admin_trans('updated')) ?></th>
                <th style="width:180px;"><?= e(admin_trans('actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($blocks as $block): ?>
            <?php $tree = block_tree($block); ?>
            <tr>
                <td>
                    <strong><?= e($block['label']) ?></strong>
                    <?php if (!empty($block['description'])): ?>
                        <small class="text-muted"><?= e($block['description']) ?></small>
                    <?php endif; ?>
                </td>
                <td><code><?= e($block['slug']) ?></code></td>
                <td><?= (int) blocks_count_components($tree) ?></td>
                <td><?= e($block['username'] ?? '—') ?></td>
                <td><?= e(format_local_datetime((int) $block['updated_at'], 'Y-m-d')) ?></td>
                <td class="actions">
                    <a class="btn-small" href="<?= e(url('admin/block') . '?id=' . (int) $block['id']) ?>">
                        <?= e(admin_trans('edit')) ?>
                    </a>

                    <form method="post" class="inline-form js-confirm-form"
                          data-confirm-title="<?= e(admin_trans('delete_block')) ?>"
                          data-confirm="<?= e(admin_trans('delete_block_confirm', ['name' => $block['label']])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $block['id'] ?>">
                        <button type="submit" class="btn-small btn-delete"><?= e(admin_trans('delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
// Keep the hidden JSON field in step with the textarea.
const treeInput = document.getElementById('block-tree-input');
const treeField = document.getElementById('block-tree');

if (treeInput && treeField) {
    const sync = () => {
        try {
            treeField.value = JSON.stringify(JSON.parse(treeInput.value || '[]'));
        } catch (error) {
            treeField.value = '[]';
        }
    };

    treeInput.addEventListener('input', sync);
    sync();
}
</script>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('block_library')) ?></h3>
<p><?= e(admin_trans('block_library_help')) ?></p>
<ul>
    <li><?= e(admin_trans('block_insert_help')) ?></li>
    <li><?= e(admin_trans('block_depth_help', ['depth' => blocks_max_depth()])) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'editor', 'section' => 'reusable-blocks'];

include CMS_PATH . '/admin/partials/layout.php';

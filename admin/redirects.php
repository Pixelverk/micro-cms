<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Redirects
|--------------------------------------------------------------------------
| Old URLs kept alive, plus the 404s worth catching. Saving or removing a
| redirect clears the cached file for that path, which is how a redirect wins
| without putting a database read in front of every cache hit.
|--------------------------------------------------------------------------
*/

$pageTitle = admin_trans('redirects');

// ----------------------------
// POST actions
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['redirect_action'] ?? '');

    if ($action === 'delete') {
        $id      = (int) ($_POST['id'] ?? 0);
        $deleted = redirect_delete($id);

        if ($deleted) {
            log_activity('redirect.deleted', 'redirect', $id, '', []);
            redirect_with_toast('redirects', 'success', admin_trans('redirect_removed'));
        }

        redirect_with_toast('redirects', 'error', admin_trans('redirect_missing'));
    }

    if ($action === 'save') {
        $fromRaw = (string) ($_POST['from_path'] ?? '');
        $toRaw   = trim((string) ($_POST['to_path'] ?? ''));
        $status  = (int) ($_POST['redirect_status'] ?? 301);

        $from   = redirect_normalize_path($fromRaw);
        $errors = [];

        if ($from === '' || redirect_is_reserved($from)) {
            $errors[] = admin_trans('redirect_bad_from');
        }

        if ($toRaw === '' || (!preg_match('#^https?://#i', $toRaw) && !preg_match('#^[a-z0-9\-/_\.]*$#i', $toRaw))) {
            $errors[] = admin_trans('redirect_bad_to');
        }

        if ($errors) {
            redirect_with_toast('redirects', 'error', implode(' ', $errors));
        }

        $savedId = redirect_save($from, $toRaw, $status);

        log_activity('redirect.saved', 'redirect', $savedId, $from, ['to' => $toRaw, 'status' => $status]);
        redirect_with_toast('redirects', 'success', admin_trans('redirect_saved'));
    }

    redirect_with_toast('redirects', 'error', admin_trans('redirect_unknown_action'));
}

// ----------------------------
// Edit state
// ----------------------------
$editId       = (int) ($_GET['edit'] ?? 0);
$editRedirect = null;

if ($editId) {
    foreach (redirect_all() as $row) {
        if ((int) $row['id'] === $editId) {
            $editRedirect = $row;
            break;
        }
    }
}

$fromValue   = (string) ($editRedirect['from_path'] ?? ($_GET['from'] ?? ''));
$toValue     = (string) ($editRedirect['to_path'] ?? '');
$statusValue = (int) ($editRedirect['status'] ?? 301);

$redirects = redirect_all();
$misses    = analytics_recent_404s(30, 20);

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('redirects')) ?></h2>
        <p><?= e(admin_trans('redirects_intro')) ?></p>
    </div>
</div>

<form method="post" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect_action" value="save">

    <fieldset>
        <legend><?= e($editId ? admin_trans('redirect_edit') : admin_trans('redirect_add')) ?></legend>

        <label class="field">
            <span class="field-label"><?= e(admin_trans('redirect_from')) ?></span>
            <input class="field-input" type="text" name="from_path" value="<?= e($fromValue) ?>"
                   placeholder="/old-url/" <?= $editId ? 'readonly' : '' ?>>
        </label>

        <label class="field">
            <span class="field-label"><?= e(admin_trans('redirect_to')) ?></span>
            <input class="field-input" type="text" name="to_path" value="<?= e($toValue) ?>"
                   placeholder="/new-url/">
        </label>

        <label class="field">
            <span class="field-label"><?= e(admin_trans('redirect_type')) ?></span>
            <select class="field-input" name="redirect_status">
                <option value="301" <?= $statusValue === 301 ? 'selected' : '' ?>><?= e(admin_trans('redirect_301')) ?></option>
                <option value="302" <?= $statusValue === 302 ? 'selected' : '' ?>><?= e(admin_trans('redirect_302')) ?></option>
            </select>
        </label>

        <div class="form-actions">
            <button type="submit"><?= e(admin_trans('redirect_save')) ?></button>
            <?php if ($editId): ?>
                <a class="btn-small btn-muted" href="<?= e(url('admin/redirects')) ?>"><?= e(admin_trans('cancel')) ?></a>
            <?php endif; ?>
        </div>
    </fieldset>
</form>

<table class="admin-table">
    <thead>
        <tr>
            <th><?= e(admin_trans('redirect_from')) ?></th>
            <th><?= e(admin_trans('redirect_to')) ?></th>
            <th><?= e(admin_trans('redirect_type')) ?></th>
            <th><?= e(admin_trans('redirect_hits')) ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$redirects): ?>
            <tr><td colspan="5"><?= e(admin_trans('redirect_none')) ?></td></tr>
        <?php else: ?>
            <?php foreach ($redirects as $row): ?>
                <tr>
                    <td><code>/<?= e($row['from_path']) ?>/</code></td>
                    <td><?= e($row['to_path']) ?></td>
                    <td><?= (int) $row['status'] ?></td>
                    <td><?= (int) $row['hits'] ?></td>
                    <td class="actions">
                        <a class="btn-small" href="<?= e(url('admin/redirects') . '?edit=' . (int) $row['id']) ?>">
                            <?= e(admin_trans('redirect_edit')) ?>
                        </a>

                        <form method="post" class="inline-form js-confirm-form"
                              data-confirm="<?= e(admin_trans('redirect_delete_confirm', ['from' => '/' . $row['from_path'] . '/'])) ?>"
                              data-confirm-title="<?= e(admin_trans('redirect_delete')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="redirect_action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button type="submit" class="btn-small btn-danger"><?= e(admin_trans('redirect_delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<h3><?= e(admin_trans('redirect_404s')) ?></h3>

<?php if (!$misses): ?>
    <p class="empty-state"><?= e(admin_trans('redirect_404_none')) ?></p>
<?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th><?= e(admin_trans('redirect_from')) ?></th>
                <th><?= e(admin_trans('redirect_hits')) ?></th>
                <th><?= e(admin_trans('redirect_last_seen')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($misses as $miss): ?>
                <tr>
                    <td><code><?= e($miss['path']) ?></code></td>
                    <td><?= (int) $miss['views'] ?></td>
                    <td><?= e(date('Y-m-d H:i', (int) $miss['last_seen'])) ?></td>
                    <td class="actions">
                        <a class="btn-small" href="<?= e(url('admin/redirects') . '?from=' . urlencode((string) $miss['path'])) ?>">
                            <?= e(admin_trans('redirect_use_404')) ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('redirects')) ?></h3>
<p><?= e(admin_trans('redirects_help')) ?></p>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'redirects'];

include CMS_PATH . '/admin/partials/layout.php';

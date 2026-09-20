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

$pageTitle = admin_trans('nav_redirects');

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
            redirect_with_toast('redirects', 'success', admin_trans('redirects_removed'));
        }

        redirect_with_toast('redirects', 'error', admin_trans('redirects_missing'));
    }

    if ($action === 'repair') {
        $removed = redirect_repair();

        if ($removed > 0) {
            log_activity('redirect.repaired', 'redirect', null, (string) $removed, []);
            redirect_with_toast('redirects', 'success', admin_trans('redirects_repair_done', ['count' => $removed]));
        }

        redirect_with_toast('redirects', 'success', admin_trans('redirects_repair_none'));
    }

    if ($action === 'save') {
        $fromRaw = (string) ($_POST['from_path'] ?? '');
        $toRaw   = trim((string) ($_POST['to_path'] ?? ''));
        $status  = (int) ($_POST['redirect_status'] ?? 301);

        // Editing posts its own id, so the entry being changed is not reported
        // as one that already owns the path.
        $editId = (int) ($_POST['id'] ?? 0);
        $from   = redirect_normalize_path($fromRaw);
        $errors = [];

        if ($toRaw === '' || (!preg_match('#^https?://#i', $toRaw) && !preg_match('#^[a-z0-9\-/_\.]*$#i', $toRaw))) {
            $errors[] = admin_trans('redirects_error_to');
        }

        // Each rule has its own sentence: "that would hide a live page" is a
        // different problem from "that path already redirects".
        $ruleKeys = [
            'empty'    => 'redirects_error_from',
            'reserved' => 'redirects_rule_reserved',
            'self'     => 'redirects_rule_self',
            'loop'     => 'redirects_rule_loop',
            'shadows'  => 'redirects_rule_shadows',
            'existing' => 'redirects_rule_existing',
        ];

        foreach (redirect_conflicts($from, $toRaw, $editId ?: null) as $problem) {
            $message = admin_trans($ruleKeys[$problem['rule']] ?? 'redirects_error_from');

            $errors[] = $problem['detail'] !== '' ? $message . ' (' . $problem['detail'] . ')' : $message;
        }

        if ($errors) {
            redirect_with_toast('redirects', 'error', implode(' ', $errors));
        }

        try {
            $savedId = redirect_save($from, $toRaw, $status, $editId ?: null);
        } catch (Throwable $exception) {
            debug_log('redirect save refused: ' . $exception->getMessage());
            redirect_with_toast('redirects', 'error', admin_trans('redirects_error_refused'));
        }

        log_activity('redirect.saved', 'redirect', $savedId, $from, ['to' => $toRaw, 'status' => $status]);
        redirect_with_toast('redirects', 'success', admin_trans('redirects_saved'));
    }

    redirect_with_toast('redirects', 'error', admin_trans('redirects_error_action'));
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

$search     = trim((string) ($_GET['q'] ?? ''));
$all        = redirect_all();
$redirects  = $search !== '' ? redirect_all(['q' => $search]) : $all;
$misses     = analytics_recent_404s(30, 20);

// One chain walk for the whole page: the list flags multi-hop targets, and the
// check reports the entries that cannot do their job.
$targets    = redirect_target_map();
$check      = !empty($_GET['check']);
$audit      = $check ? redirect_audit() : [];
$repairable = 0;

foreach ($audit as $finding) {
    if ($finding['kind'] !== 'chain') {
        $repairable++;
    }
}

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('nav_redirects')) ?></h2>
        <p><?= e(admin_trans('redirects_intro')) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('admin/redirects') . '?check=1') ?>">
            <?= icon('clipboard-check', 16) ?><?= e(admin_trans('redirects_check')) ?>
        </a>
    </div>
</div>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect_action" value="save">
    <input type="hidden" name="id" value="<?= (int) $editId ?>">

    <fieldset class="settings-group">
        <legend>
            <?= icon('open-in-browser', 18) ?>
            <?= e($editId ? admin_trans('redirects_edit') : admin_trans('redirects_add')) ?>
        </legend>

        <div class="field-grid field-grid-inline card">
            <div class="field">
                <label class="field-label" for="redirect-from"><?= e(admin_trans('redirects_from')) ?></label>
                <input class="field-input" type="text" id="redirect-from" name="from_path" value="<?= e($fromValue) ?>"
                       placeholder="/old-url/" <?= $editId ? 'readonly' : '' ?>>
                <?php if ($editId): ?>
                    <small><?= e(admin_trans('redirects_from_fixed')) ?></small>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label" for="redirect-to"><?= e(admin_trans('redirects_to')) ?></label>
                <input class="field-input" type="text" id="redirect-to" name="to_path" value="<?= e($toValue) ?>"
                       placeholder="/new-url/">
            </div>

            <div class="field field-narrow">
                <label class="field-label" for="redirect-status"><?= e(admin_trans('redirects_type')) ?></label>
                <select class="field-input" id="redirect-status" name="redirect_status">
                    <option value="301" <?= $statusValue === 301 ? 'selected' : '' ?>><?= e(admin_trans('redirects_301')) ?></option>
                    <option value="302" <?= $statusValue === 302 ? 'selected' : '' ?>><?= e(admin_trans('redirects_302')) ?></option>
                </select>
            </div>

            <div class="field field-btn">
                <div class="form-actions">
                    <button type="submit" class="btn"><?= e(admin_trans('redirects_save')) ?></button>
                    <?php if ($editId): ?>
                        <a class="btn-muted" href="<?= e(url('admin/redirects')) ?>"><?= e(admin_trans('common_cancel')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </fieldset>
</form>

<?php if ($check): ?>
    <fieldset class="settings-group">
        <legend>
            <?= icon('clipboard-check', 18) ?>
            <?= e(admin_trans('redirects_check_title')) ?>
        </legend>

        <?php if (!$audit): ?>
            <p class="field-note"><?= e(admin_trans('redirects_check_clean')) ?></p>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th><?= e(admin_trans('redirects_check_problem')) ?></th>
                        <th><?= e(admin_trans('redirects_from')) ?></th>
                        <th><?= e(admin_trans('redirects_to')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit as $finding): ?>
                        <?php $chain = $finding['kind'] === 'chain'; ?>
                        <tr>
                            <td>
                                <span class="<?= $chain ? 'status-warning' : 'status-failed' ?>">
                                    <?= e(admin_trans('redirects_check_kind_' . $finding['kind'])) ?>
                                </span>
                            </td>
                            <td><code>/<?= e($finding['from_path']) ?>/</code></td>
                            <td>
                                <?= e($finding['to_path']) ?>
                                <?php if ($finding['detail'] !== ''): ?>
                                    <small class="field-note"><?= e($finding['detail']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($repairable > 0): ?>
                <form method="post" class="form-actions js-confirm-form"
                      data-confirm="<?= e(admin_trans('redirects_repair_confirm')) ?>"
                      data-confirm-title="<?= e(admin_trans('redirects_repair')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="redirect_action" value="repair">
                    <button type="submit" class="btn-danger">
                        <?= e(admin_trans('redirects_repair', ['count' => $repairable])) ?>
                    </button>
                </form>
            <?php else: ?>
                <p class="field-note"><?= e(admin_trans('redirects_repair_chains_only')) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </fieldset>
<?php endif; ?>

<fieldset class="settings-group">
    <legend>
        <?= icon('open-in-browser', 18) ?>
        <?= e(admin_trans('redirects_title')) ?>
    </legend>

    <?php if ($all): ?>
        <div class="content-filters">
            <form method="get" class="content-search">
                <label class="off-screen" for="redirects-search"><?= e(admin_trans('redirects_search')) ?></label>
                <input type="search" id="redirects-search" name="q" value="<?= e($search) ?>"
                       placeholder="<?= e(admin_trans('redirects_search')) ?>">
                <?php if ($search !== ''): ?>
                    <a href="<?= e(url('admin/redirects')) ?>" class="btn-small btn-muted"><?= e(admin_trans('common_clear')) ?></a>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <?php if (!$redirects): ?>
        <div class="empty-state">
            <span class="empty-state-icon" aria-hidden="true"><?= icon('open-in-browser', 24) ?></span>
            <p class="empty-state-title">
                <?= e($search !== '' ? admin_trans('redirects_empty_filtered', ['q' => $search]) : admin_trans('redirects_empty')) ?>
            </p>
        </div>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?= e(admin_trans('redirects_from')) ?></th>
                    <th><?= e(admin_trans('redirects_to')) ?></th>
                    <th><?= e(admin_trans('redirects_type')) ?></th>
                    <th><?= e(admin_trans('redirects_hits')) ?></th>
                    <th class="col-actions col-actions-icons"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($redirects as $row): ?>
                    <tr>
                        <td><code>/<?= e($row['from_path']) ?>/</code></td>
                        <td>
                            <?= e($row['to_path']) ?>
                            <?php $hops = redirect_chain_depth((string) $row['from_path'], $targets); ?>
                            <?php if ($hops > 1): ?>
                                <span class="status-warning" title="<?= e(admin_trans('redirects_chain_help')) ?>">
                                    <?= e(admin_trans('redirects_chain', ['hops' => $hops])) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge"><?= (int) $row['status'] ?></span>
                        </td>
                        <td><?= (int) $row['hits'] ?></td>
                        <td class="actions col-actions-icons">
                            <a href="<?= e(url('admin/redirects') . '?edit=' . (int) $row['id']) ?>"
                               class="btn-small btn-icon"
                               title="<?= e(admin_trans('redirects_edit')) ?>"
                               aria-label="<?= e(admin_trans('redirects_edit')) ?>">
                                <?= icon('edit', 16) ?>
                            </a>

                            <form method="post" class="inline-form js-confirm-form"
                                  data-confirm="<?= e(admin_trans('redirects_delete_confirm', ['from' => '/' . $row['from_path'] . '/'])) ?>"
                                  data-confirm-title="<?= e(admin_trans('redirects_delete')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="redirect_action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn-delete btn-small btn-icon"
                                        title="<?= e(admin_trans('redirects_delete')) ?>"
                                        aria-label="<?= e(admin_trans('redirects_delete')) ?>">
                                    <?= icon('trash', 16) ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</fieldset>

<fieldset class="settings-group">
    <legend>
        <?= icon('clock', 18) ?>
        <?= e(admin_trans('redirects_404_title')) ?>
    </legend>

    <?php if (!$misses): ?>
        <p class="empty-state"><?= e(admin_trans('redirects_404_empty')) ?></p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?= e(admin_trans('redirects_from')) ?></th>
                    <th><?= e(admin_trans('redirects_hits')) ?></th>
                    <th><?= e(admin_trans('redirects_last_seen')) ?></th>
                    <th class="col-actions col-actions-icons"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($misses as $miss): ?>
                    <tr>
                        <td><code><?= e($miss['path']) ?></code></td>
                        <td><?= (int) $miss['views'] ?></td>
                        <td><?= e(date('Y-m-d H:i', (int) $miss['last_seen'])) ?></td>
                        <td class="actions col-actions-icons">
                            <a href="<?= e(url('admin/redirects') . '?from=' . urlencode((string) $miss['path'])) ?>"
                               class="btn-small btn-icon"
                               title="<?= e(admin_trans('redirects_use_404')) ?>"
                               aria-label="<?= e(admin_trans('redirects_use_404')) ?>">
                                <?= icon('corner-down-right', 16) ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</fieldset>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('nav_redirects')) ?></h3>
<p><?= e(admin_trans('redirects_help')) ?></p>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'redirects'];

include CMS_PATH . '/admin/partials/layout.php';

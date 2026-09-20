<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Form submissions
|--------------------------------------------------------------------------
|
| The form inbox: filter by form and workflow status, read the submitted
| answers, change status per row or in bulk, and export the current view as
| CSV. Paging reuses the shared pagination helper, because an inbox grows
| without bound.
|
*/

$pageTitle = admin_trans('forms_title');

$theme     = theme_config();
$formTypes = $theme['form_types'] ?? [];

// ----------------------------
// Filters
// ----------------------------
$activeForm   = isset($formTypes[(string) ($_GET['form'] ?? '')]) ? (string) $_GET['form'] : '';
$activeStatus = form_submission_status_valid($_GET['status'] ?? null) ?? '';
$search       = trim((string) ($_GET['q'] ?? ''));

$filters = ['form' => $activeForm, 'status' => $activeStatus];
if ($search !== '') {
    $filters['q'] = $search;
}

// Carried by every link and form, so an action returns to the same view.
$keepQuery = array_filter([
    'form'   => $activeForm,
    'status' => $activeStatus,
    'q'      => $search,
], static fn($value) => $value !== '');

$viewUrl = function (array $overrides = []) use ($keepQuery): string {
    $query = array_filter(
        array_merge($keepQuery, $overrides),
        static fn($value) => $value !== '' && $value !== null
    );

    return url('admin/messages') . ($query ? '?' . http_build_query($query) : '');
};

// ----------------------------
// CSV export
// ----------------------------
// The default is every submission, whatever the filters; passing the filters
// through exports the current view instead.
if (($_GET['export'] ?? '') === 'csv') {
    $scoped = !empty($_GET['scoped']);

    form_submission_export_csv(
        form_submission_all($scoped ? $filters : []),
        'form-submissions' . ($scoped && $activeForm !== '' ? '-' . $activeForm : '') . '.csv'
    );
    exit;
}

// ----------------------------
// One page of submissions
// ----------------------------
$page   = pagination_current_page();
$result = form_submission_page($filters, $page, 20);

// Counts are computed before filtering so every tab shows its own total.
$totalCount = form_submission_count(['form' => $activeForm]);
$statusCounts = [];
foreach (form_submission_statuses() as $status) {
    $statusCounts[$status] = form_submission_count(['form' => $activeForm, 'status' => $status]);
}

// A headline per row: something recognisable before opening the details.
$headline = function (array $data): string {
    foreach (['name', 'email', 'subject'] as $key) {
        if (!empty($data[$key]) && is_string($data[$key])) {
            return $data[$key];
        }
    }

    foreach ($data as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
};

// ----------------------------
// Render page
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('forms_title')) ?></h2>
        <p><?= e(admin_trans('forms_intro')) ?></p>
    </div>
    <div class="page-actions">
        <label class="flex items-center gap-sm mb-0">
            <span class="nowrap"><?= e(admin_trans('forms_type')) ?></span>
            <select id="messages-type-select">
                <option value=""><?= e(admin_trans('forms_all')) ?></option>
                <?php foreach ($formTypes as $key => $meta): ?>
                    <option value="<?= e($key) ?>" <?= $key === $activeForm ? 'selected' : '' ?>>
                        <?= e($meta['label'] ?? ucfirst($key)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <a class="btn-primary" href="<?= e(url('admin/messages') . '?export=csv') ?>">
            <?= icon('clipboard-check', 16) ?><?= e(admin_trans('forms_export_all')) ?>
        </a>
    </div>
</div>

<div class="content-filters">
    <div class="status-tabs">
        <a href="<?= e($viewUrl(['status' => ''])) ?>"
           class="status-tab <?= $activeStatus === '' ? 'active' : '' ?>">
            <?= e(admin_trans('forms_status_any')) ?>
            <span class="status-tab-count"><?= (int) $totalCount ?></span>
        </a>
        <?php foreach (form_submission_statuses() as $status): ?>
            <a href="<?= e($viewUrl(['status' => $status])) ?>"
               class="status-tab <?= $activeStatus === $status ? 'active' : '' ?>">
                <?= e(form_submission_status_label($status)) ?>
                <span class="status-tab-count"><?= (int) ($statusCounts[$status] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="content-search">
        <?php if ($activeForm !== ''): ?>
            <input type="hidden" name="form" value="<?= e($activeForm) ?>">
        <?php endif; ?>
        <?php if ($activeStatus !== ''): ?>
            <input type="hidden" name="status" value="<?= e($activeStatus) ?>">
        <?php endif; ?>
        <input type="search" name="q" value="<?= e($search) ?>"
               placeholder="<?= e(admin_trans('forms_search')) ?>" aria-label="<?= e(admin_trans('forms_search')) ?>">
        <?php if ($search !== ''): ?>
            <a href="<?= e($viewUrl(['q' => ''])) ?>" class="btn-small btn-muted"><?= e(admin_trans('common_clear')) ?></a>
        <?php endif; ?>
    </form>
</div>

<?php if (!$result['items']): ?>
    <div class="empty-state">
        <span class="empty-state-icon" aria-hidden="true"><?= icon('mail-in', 24) ?></span>
        <p class="empty-state-title"><?= e(admin_trans('forms_empty')) ?></p>
    </div>
<?php else: ?>

    <form method="post" action="<?= url('admin/messages/update') ?>" id="messages-form">
        <?= csrf_field() ?>
        <input type="hidden" name="return_form" value="<?= e($activeForm) ?>">
        <input type="hidden" name="return_status" value="<?= e($activeStatus) ?>">
        <input type="hidden" name="return_page" value="<?= (int) $result['page'] ?>">

        <div class="bulk-toolbar" id="messages-bulk-toolbar" hidden>
            <span class="bulk-count"><strong id="messages-bulk-count">0</strong> <?= e(admin_trans('bulk_selected')) ?></span>

            <label>
                <span class="visually-hidden"><?= e(admin_trans('forms_bulk_label')) ?></span>
                <select name="action" class="field-input">
                    <option value=""><?= e(admin_trans('forms_bulk_choose')) ?></option>
                    <optgroup label="<?= e(admin_trans('forms_bulk_set_status')) ?>">
                        <?php foreach (form_submission_statuses() as $status): ?>
                            <option value="<?= e($status) ?>"><?= e(form_submission_status_label($status)) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="<?= e(admin_trans('forms_bulk_other')) ?>">
                        <option value="export"><?= e(admin_trans('forms_export_selected')) ?></option>
                        <option value="delete"><?= e(admin_trans('common_delete')) ?></option>
                    </optgroup>
                </select>
            </label>

            <button type="submit" class="btn-small btn-primary"><?= e(admin_trans('forms_bulk_apply')) ?></button>
            <button type="button" class="btn-small btn-muted" id="messages-bulk-clear"><?= e(admin_trans('bulk_clear_selection')) ?></button>
        </div>
        <table class="admin-table messages-table">
            <thead>
                <tr>
                    <th class="col-select">
                        <input type="checkbox" id="messages-select-all"
                               aria-label="<?= e(admin_trans('bulk_select_all')) ?>">
                    </th>
                    <th><?= e(admin_trans('forms_submission')) ?></th>
                    <th class="col-status"><?= e(admin_trans('common_status')) ?></th>
                    <th class="col-status-control"><?= e(admin_trans('forms_set_status')) ?></th>
                    <th><?= e(admin_trans('forms_submitted_at')) ?></th>
                    <th class="col-actions"><?= e(admin_trans('common_actions')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['items'] as $row): ?>
                    <?php
                    $rowId    = (int) $row['id'];
                    $data     = $row['data'];
                    $status   = (string) $row['status'];
                    $summary  = $headline($data);
                    $formName = $formTypes[$row['form_type']]['label'] ?? ucfirst((string) $row['form_type']);
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="messages-row" name="ids[]"
                                   value="<?= $rowId ?>" form="messages-form"
                                   aria-label="<?= e($formName) ?>">
                        </td>

                        <?php /* The summary itself opens the same modal as the view button. */ ?>
                        <td>
                            <button type="button" class="btn-text js-submission-view"
                                    data-modal="submission-view-<?= $rowId ?>"
                                    aria-haspopup="dialog">
                                <strong><?= e($formName) ?></strong>
                                <?php if ($summary !== ''): ?>
                                    <span class="text-muted">— <?= e($summary) ?></span>
                                <?php endif; ?>
                            </button>
                        </td>

                        <td class="col-status">
                            <span class="status status-<?= e($status) ?>">
                                <?= e(form_submission_status_label($status)) ?>
                            </span>
                        </td>

                        <?php /* Its own form, so changing one row does not submit the others. */ ?>
                        <td class="col-status-control">
                            <form method="post" action="<?= url('admin/messages/update') ?>" class="inline-form js-row-status">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return_form" value="<?= e($activeForm) ?>">
                                <input type="hidden" name="return_status" value="<?= e($activeStatus) ?>">
                                <input type="hidden" name="return_page" value="<?= (int) $result['page'] ?>">
                                <input type="hidden" name="row_id" value="<?= $rowId ?>">

                                <select name="row_status" class="field-input"
                                        onchange="this.form.submit()"
                                        aria-label="<?= e(admin_trans('forms_set_status')) ?>">
                                    <?php foreach (form_submission_statuses() as $option): ?>
                                        <option value="<?= e($option) ?>" <?= $option === $status ? 'selected' : '' ?>>
                                            <?= e(form_submission_status_label($option)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>

                        <td><?= e(format_local_datetime($row['created_at'], 'Y-m-d H:i')) ?></td>

                        <td class="actions col-actions">
                            <button type="button" class="btn-secondary btn-small js-submission-view"
                                    data-modal="submission-view-<?= $rowId ?>"
                                    aria-haspopup="dialog">
                                <?= icon('search', 16) ?><?= e(admin_trans('common_view')) ?>
                            </button>

                            <form method="post" action="<?= url('admin/messages/update') ?>"
                                  class="inline-form js-confirm-form"
                                  data-confirm="<?= e(admin_trans('forms_delete_confirm', ['name' => $formName])) ?>"
                                  data-confirm-title="<?= e(admin_trans('common_delete')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return_form" value="<?= e($activeForm) ?>">
                                <input type="hidden" name="return_status" value="<?= e($activeStatus) ?>">
                                <input type="hidden" name="return_page" value="<?= (int) $result['page'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="ids[]" value="<?= $rowId ?>">
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
    </form>

    <?php /* One modal per submission, opened by that row's view button.
               Kept outside the table so the rows keep their column count. */ ?>
    <?php foreach ($result['items'] as $row): ?>
        <?php $rowId = (int) $row['id']; ?>
        <div id="submission-view-<?= $rowId ?>" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="submission-view-<?= $rowId ?>-title" hidden>
            <div class="modal" tabindex="-1">
                <div class="modal-header">
                    <h3 id="submission-view-<?= $rowId ?>-title">
                        <?= e($formTypes[$row['form_type']]['label'] ?? ucfirst((string) $row['form_type'])) ?>
                        <span class="text-muted"><?= e(format_local_datetime($row['created_at'], 'Y-m-d H:i')) ?></span>
                    </h3>
                    <button type="button" class="close-modal js-submission-close"
                            aria-label="<?= e(admin_trans('common_close')) ?>">&times;</button>
                </div>

                <div class="modal-body">
                    <ul class="messages-data">
                        <?php foreach ($row['data'] as $key => $value): ?>
                            <li>
                                <strong><?= e(form_submission_field_label((string) $key)) ?>:</strong>
                                <?php if (is_array($value)): ?>
                                    <?= e(implode(', ', array_map('strval', $value))) ?>
                                <?php else: ?>
                                    <?= nl2br(e((string) $value)) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if (!$row['data']): ?>
                        <p class="text-muted"><?= e(admin_trans('forms_no_data')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="modal-actions">
                    <span class="status status-<?= e((string) $row['status']) ?>">
                        <?= e(form_submission_status_label((string) $row['status'])) ?>
                    </span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php
    if ((int) $result['pages'] > 1) {
        ?>
        <nav class="pagination" aria-label="<?= e(admin_trans('forms_pages')) ?>">
            <?php if ((int) $result['page'] > 1): ?>
                <a class="btn-secondary btn-small" href="<?= e($viewUrl(['page' => (int) $result['page'] - 1])) ?>">&larr; Previous</a>
            <?php endif; ?>
            <span class="text-muted">
                Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?>
            </span>
            <?php if ((int) $result['page'] < (int) $result['pages']): ?>
                <a class="btn-secondary btn-small" href="<?= e($viewUrl(['page' => (int) $result['page'] + 1])) ?>">Next &rarr;</a>
            <?php endif; ?>
        </nav>
        <?php
    }
    ?>

<?php endif; ?>

<script>
/* Select-all and the bulk toolbar, mirroring the content list. */
(() => {
    const all = document.getElementById('messages-select-all');
    if (!all) return;

    const boxes = Array.from(document.querySelectorAll('.messages-row'));
    const toolbar = document.getElementById('messages-bulk-toolbar');
    const countEl = document.getElementById('messages-bulk-count');
    const clearBtn = document.getElementById('messages-bulk-clear');

    const selected = () => boxes.filter(box => box.checked);

    function sync() {
        const chosen = selected();

        if (toolbar) toolbar.hidden = chosen.length === 0;
        if (countEl) countEl.textContent = chosen.length;

        all.checked = chosen.length > 0 && chosen.length === boxes.length;
        all.indeterminate = chosen.length > 0 && chosen.length < boxes.length;
    }

    boxes.forEach(box => box.addEventListener('change', sync));

    all.addEventListener('change', () => {
        boxes.forEach(box => { box.checked = all.checked; });
        sync();
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            boxes.forEach(box => { box.checked = false; });
            all.checked = false;
            sync();
        });
    }

    sync();
})();

/* The form-type select sits in the page header, so it navigates on change. */
const messagesTypeSelect = document.getElementById('messages-type-select');
if (messagesTypeSelect) {
    messagesTypeSelect.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.delete('page');

        if (messagesTypeSelect.value === '') {
            url.searchParams.delete('form');
        } else {
            url.searchParams.set('form', messagesTypeSelect.value);
        }

        window.location.href = url.toString();
    });
}

/* Submission details, in the shared modal style used elsewhere in the admin. */
(() => {
    document.querySelectorAll('.js-submission-view').forEach(button => {
        const backdrop = document.getElementById(button.dataset.modal);
        if (!backdrop) return;

        button.addEventListener('click', (event) => openDialog(backdrop, event.currentTarget));

        // Clicking the backdrop (but not the dialog) closes it.
        backdrop.addEventListener('click', event => {
            if (event.target === backdrop) closeDialog(backdrop);
        });

        backdrop.querySelectorAll('.js-submission-close').forEach(closeButton => {
            closeButton.addEventListener('click', () => closeDialog(backdrop));
        });
    });
})();
</script>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('forms_title')) ?></h3>
<p><?= e(admin_trans('forms_help')) ?></p>
<p><?= e(admin_trans('forms_help_status')) ?></p>
<p><?= e(admin_trans('forms_help_email')) ?></p>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'form-submissions'];

include CMS_PATH . '/admin/partials/layout.php';

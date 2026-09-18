<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Form submission actions and export
|--------------------------------------------------------------------------
|
| One POST endpoint for the inbox. It accepts three shapes, which keeps the
| page to a couple of forms rather than one per row:
|
|   row_id + row_status  one row, submitted when its select changes
|   ids[] + action=<status>   bulk status change for the ticked rows
|   ids[] + action=delete     delete the ticked rows
|   ids[] + action=export     download the ticked rows as CSV
|
| Nothing here trusts the posted ids beyond looking them up, and an unknown
| status is refused rather than written.
|
*/

require_capability('forms.view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(admin_trans('error_method'));
}

const MESSAGES_BULK_LIMIT = 500;

$theme     = theme_config();
$formTypes = $theme['form_types'] ?? [];

// Return the user to the view they were working in.
$returnForm   = (string) ($_POST['return_form'] ?? '');
$returnStatus = (string) ($_POST['return_status'] ?? '');
$returnPage   = max(1, (int) ($_POST['return_page'] ?? 1));

$returnQuery = array_filter([
    'form'   => $returnForm !== '' && isset($formTypes[$returnForm]) ? $returnForm : '',
    'status' => form_submission_status_valid($returnStatus) ?? '',
    'page'   => $returnPage > 1 ? $returnPage : '',
], static fn($value) => $value !== '' && $value !== null);

$redirect = function (string $type, string $message) use ($returnQuery): void {
    redirect_with_toast('messages', $type, $message, $returnQuery);
};

/**
 * The ticked ids, capped.
 *
 * @return list<int>
 */
$selectedIds = static function (): array {
    return array_slice(array_values(array_filter(
        array_map('intval', (array) ($_POST['ids'] ?? [])),
        static fn(int $id) => $id > 0
    )), 0, MESSAGES_BULK_LIMIT);
};

$action = (string) ($_POST['action'] ?? '');

// ----------------------------
// Export the selected rows
// ----------------------------
if ($action === 'export') {
    $ids = $selectedIds();

    if (!$ids) {
        $redirect('error', admin_trans('forms_error_no_selection'));
    }

    form_submission_export_csv(form_submission_by_ids($ids), 'form-submissions-selected.csv');
    exit;
}

// ----------------------------
// Delete the selected rows
// ----------------------------
if ($action === 'delete') {
    $ids = $selectedIds();

    if (!$ids) {
        $redirect('error', admin_trans('forms_error_no_selection'));
    }

    $deleted = form_submission_delete($ids);

    if ($deleted > 0) {
        log_activity('form.deleted', 'form', null, $deleted . ' submission(s)', ['ids' => $ids]);
    }

    $redirect('success', admin_trans('forms_deleted', ['count' => $deleted]));
}

// ----------------------------
// Bulk: apply one status to the selected rows
// ----------------------------
$bulkStatus = form_submission_status_valid($action);

if ($bulkStatus !== null) {
    $ids = $selectedIds();

    if (!$ids) {
        $redirect('error', admin_trans('forms_error_no_selection'));
    }

    $changed = form_submission_set_status($ids, $bulkStatus);

    if ($changed > 0) {
        log_activity('form.status', 'form', null, $changed . ' x ' . form_submission_status_label($bulkStatus), [
            'status' => $bulkStatus,
            'ids'    => $ids,
        ]);
    }

    $redirect('success', admin_trans('forms_bulk_done', [
        'count'  => $changed,
        'status' => form_submission_status_label($bulkStatus),
    ]));
}

// ----------------------------
// Per row: a single select changed
// ----------------------------
$postedId     = (int) ($_POST['row_id'] ?? 0);
$postedStatus = form_submission_status_valid($_POST['row_status'] ?? null);

if ($postedId < 1 || $postedStatus === null) {
    $redirect('error', admin_trans('forms_error_bad_row'));
}

$changed = form_submission_set_status([$postedId], $postedStatus);

if ($changed === 0) {
    $redirect('info', admin_trans('forms_status_unchanged'));
}

log_activity('form.status', 'form', $postedId, '1 x ' . form_submission_status_label($postedStatus), [
    'status' => $postedStatus,
]);

$redirect('success', admin_trans('forms_status_saved', ['count' => $changed]));

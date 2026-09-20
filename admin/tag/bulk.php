<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bulk tag delete
|--------------------------------------------------------------------------
|
| One POST endpoint for the tags list's bulk toolbar. Every id goes
| through bulk_delete_taxonomies(), which deletes each term with the same
| taxonomy_delete() the row button uses, so the two paths cannot drift.
|
| No transaction: a term that is already gone is skipped and counted rather
| than failing the rest of the selection.
|
*/

require_capability('taxonomy.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(admin_trans('error_method'));
}

const TAXONOMY_BULK_LIMIT = 200;

$rawIds = $_POST['ids'] ?? [];

if (!is_array($rawIds)) {
    $rawIds = [$rawIds];
}

$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn($id) => $id > 0)));

if (!$ids) {
    redirect_with_toast('tag', 'error', admin_trans('bulk_error_no_selection'));
}

if (count($ids) > TAXONOMY_BULK_LIMIT) {
    redirect_with_toast('tag', 'error', admin_trans('bulk_error_limit', ['count' => TAXONOMY_BULK_LIMIT]));
}

$result = bulk_delete_taxonomies('tag', $ids);

if ($result['removed'] === 0) {
    redirect_with_toast('tag', 'error', admin_trans('bulk_error_none_changed'));
}

log_activity('taxonomy.bulk_delete', 'taxonomy', null, $result['removed'] . ' tag(s)', [
    'ids'     => $ids,
    'skipped' => $result['skipped'],
]);

$message = admin_trans('bulk_summary_removed', ['count' => $result['removed']]);

if ($result['skipped'] > 0) {
    $message .= ' ' . admin_trans('bulk_summary_skipped', ['count' => $result['skipped']]);
}

redirect_with_toast('tag', $result['skipped'] > 0 ? 'error' : 'success', $message);

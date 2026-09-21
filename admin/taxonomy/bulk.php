<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bulk taxonomy delete
|--------------------------------------------------------------------------
|
| One POST endpoint for every taxonomy list's bulk toolbar, for the taxonomy
| named by ?type=. Every id goes through bulk_delete_taxonomies(), which
| deletes each term with the same taxonomy_delete() the row button uses, so the
| two paths cannot drift.
|
| No transaction: a term that is already gone is skipped and counted rather
| than failing the rest of the selection.
|
*/

require_capability('taxonomy.manage');

$type = (string) ($_GET['type'] ?? '');

if (taxonomy_config($type) === null) {
    redirect_with_toast('dashboard', 'error', admin_trans('taxonomy_error_not_found', ['label' => 'Taxonomy']));
}

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
    redirect_with_toast('taxonomy', 'error', admin_trans('bulk_error_no_selection'), ['type' => $type]);
}

if (count($ids) > TAXONOMY_BULK_LIMIT) {
    redirect_with_toast('taxonomy', 'error', admin_trans('bulk_error_limit', ['count' => TAXONOMY_BULK_LIMIT]), ['type' => $type]);
}

$result = bulk_delete_taxonomies($type, $ids);

if ($result['removed'] === 0) {
    redirect_with_toast('taxonomy', 'error', admin_trans('bulk_error_none_changed'), ['type' => $type]);
}

log_activity('taxonomy.bulk_delete', 'taxonomy', null, $result['removed'] . ' ' . $type . '(s)', [
    'ids'     => $ids,
    'skipped' => $result['skipped'],
]);

$message = admin_trans('bulk_summary_removed', ['count' => $result['removed']]);

if ($result['skipped'] > 0) {
    $message .= ' ' . admin_trans('bulk_summary_skipped', ['count' => $result['skipped']]);
}

redirect_with_toast('taxonomy', $result['skipped'] > 0 ? 'error' : 'success', $message, ['type' => $type]);

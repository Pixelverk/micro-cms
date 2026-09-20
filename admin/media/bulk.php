<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bulk media delete
|--------------------------------------------------------------------------
|
| One POST endpoint for the media library's bulk toolbar. Each id is re-read
| and deleted through the same media_delete() the single-file button uses, so
| the two paths cannot drift, and the summary reports what happened.
|
| No transaction: removing files is not something the database can roll back,
| so a row that fails part way is skipped and counted rather than half-undone.
|
*/

require_capability('media.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(admin_trans('error_method'));
}

const MEDIA_BULK_LIMIT = 200;

$rawIds = $_POST['ids'] ?? [];

if (!is_array($rawIds)) {
    $rawIds = [$rawIds];
}

$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn($id) => $id > 0)));

if (!$ids) {
    redirect_with_toast('media', 'error', admin_trans('bulk_error_no_selection'));
}

if (count($ids) > MEDIA_BULK_LIMIT) {
    redirect_with_toast('media', 'error', admin_trans('bulk_error_limit', ['count' => MEDIA_BULK_LIMIT]));
}

$deleted = 0;
$skipped = 0;

foreach ($ids as $id) {
    if (media_delete($id) === 'deleted') {
        $deleted++;
    } else {
        $skipped++;
    }
}

if ($deleted === 0) {
    redirect_with_toast('media', 'error', admin_trans('bulk_error_none_changed'));
}

log_activity('media.bulk_delete', 'media', null, $deleted . ' file(s)', [
    'ids'     => $ids,
    'skipped' => $skipped,
]);

$summary = admin_trans('media_bulk_deleted', ['count' => $deleted]);

if ($skipped > 0) {
    $summary .= ' ' . admin_trans('bulk_summary_skipped', ['count' => $skipped]);
}

redirect_with_toast('media', 'success', $summary);

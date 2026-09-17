<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Activity log
|--------------------------------------------------------------------------
| Read-only audit trail with filters and pagination.
|--------------------------------------------------------------------------
*/

$pageTitle = admin_trans('nav_activity');

// ----------------------------
// Filters
// ----------------------------
$filters = [
    'action'      => trim((string) ($_GET['action'] ?? '')),
    'object_type' => trim((string) ($_GET['object'] ?? '')),
    'user_id'     => (int) ($_GET['user'] ?? 0),
    'search'      => trim((string) ($_GET['q'] ?? '')),
];

$range = (string) ($_GET['range'] ?? '');
if ($range !== '') {
    $days = max(1, (int) $range);
    $filters['since'] = time() - ($days * 86400);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$result = list_activity($filters, $perPage, ($page - 1) * $perPage);
$items = $result['items'];
$total = $result['total'];
$pages = max(1, (int) ceil($total / $perPage));

$filterUrl = function (array $overrides = []) use ($filters, $range): string {
    $query = array_filter(array_merge([
        'action' => $filters['action'],
        'object' => $filters['object_type'],
        'user'   => $filters['user_id'] ?: '',
        'q'      => $filters['search'],
        'range'  => $range,
    ], $overrides), fn($value) => $value !== '' && $value !== null);

    return url('admin/activity') . ($query ? '?' . http_build_query($query) : '');
};

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('nav_activity')) ?></h2>
        <p><?= (int) $total ?> <?= e(admin_trans('activity_entries')) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn-small btn-muted" href="<?= e($filterUrl(['action' => '', 'object' => '', 'user' => '', 'q' => '', 'range' => ''])) ?>">
            <?= e(admin_trans('activity_clear_filters')) ?>
        </a>
    </div>
</div>

<form method="get" class="content-filters activity-filters">
    <div class="filter-row">
        <label>
            <span><?= e(admin_trans('common_type')) ?></span>
            <select name="action">
                <option value=""><?= e(admin_trans('activity_all')) ?></option>
                <?php foreach (activity_groups() as $group): ?>
                    <option value="<?= e($group) ?>" <?= $filters['action'] === $group ? 'selected' : '' ?>>
                        <?= e(ucfirst($group)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <span><?= e(admin_trans('activity_object')) ?></span>
            <select name="object">
                <option value=""><?= e(admin_trans('activity_any')) ?></option>
                <?php foreach (['content', 'media', 'user', 'taxonomy', 'menu', 'settings', 'utility'] as $object): ?>
                    <option value="<?= e($object) ?>" <?= $filters['object_type'] === $object ? 'selected' : '' ?>>
                        <?= e(ucfirst($object)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <span><?= e(admin_trans('common_author')) ?></span>
            <select name="user">
                <option value=""><?= e(admin_trans('activity_anyone')) ?></option>
                <?php foreach (activity_actors() as $actor): ?>
                    <option value="<?= (int) $actor['user_id'] ?>" <?= $filters['user_id'] === (int) $actor['user_id'] ? 'selected' : '' ?>>
                        <?= e((string) $actor['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <span><?= e(admin_trans('activity_period')) ?></span>
            <select name="range">
                <option value=""><?= e(admin_trans('activity_all_time')) ?></option>
                <?php foreach ([1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $value => $label): ?>
                    <option value="<?= (int) $value ?>" <?= $range === (string) $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="grow">
            <span><?= e(admin_trans('common_search')) ?></span>
            <input type="search" name="q" value="<?= e($filters['search']) ?>" placeholder="<?= e(admin_trans('activity_search')) ?>">
        </label>

        <button type="submit" class="btn-small"><?= e(admin_trans('activity_filter')) ?></button>
    </div>
</form>

<?php if (empty($items)): ?>
    <p class="empty-state"><?= e(admin_trans('activity_empty')) ?></p>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th style="width:170px;"><?= e(admin_trans('activity_when')) ?></th>
                <th style="width:170px;"><?= e(admin_trans('common_author')) ?></th>
                <th><?= e(admin_trans('activity_action')) ?></th>
                <th><?= e(admin_trans('activity_subject')) ?></th>
                <th><?= e(admin_trans('activity_details')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $entry): ?>
            <?php $meta = json_decode((string) ($entry['meta'] ?? ''), true); ?>
            <tr>
                <td>
                    <?= e(format_local_datetime((int) $entry['created_at'], 'Y-m-d H:i')) ?>
                </td>
                <td>
                    <?php if (!empty($entry['username'])): ?>
                        <?= e((string) $entry['username']) ?>
                    <?php else: ?>
                        <span class="text-muted"><?= e(admin_trans('activity_system')) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge"><?= e(activity_action_label((string) $entry['action'])) ?></span>
                    <small class="text-muted"><code><?= e((string) $entry['action']) ?></code></small>
                </td>
                <td>
                    <?php if (!empty($entry['summary'])): ?>
                        <?= e((string) $entry['summary']) ?>
                    <?php endif; ?>
                    <?php if (!empty($entry['object_type'])): ?>
                        <small class="text-muted">
                            <?= e((string) $entry['object_type']) ?><?= $entry['object_id'] ? ' #' . (int) $entry['object_id'] : '' ?>
                        </small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (is_array($meta) && $meta): ?>
                        <small class="text-muted">
                            <?= e(implode(' · ', array_map(
                                fn($key, $value) => $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value)),
                                array_keys($meta),
                                $meta
                            ))) ?>
                        </small>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="<?= e(admin_trans('common_pagination')) ?>">
            <?php if ($page > 1): ?>
                <a class="btn-small" href="<?= e($filterUrl(['page' => $page - 1])) ?>">&larr; <?= e(admin_trans('common_previous')) ?></a>
            <?php endif; ?>

            <span class="pagination-status">
                <?= e(admin_trans('common_page_of', ['page' => $page, 'pages' => $pages])) ?>
            </span>

            <?php if ($page < $pages): ?>
                <a class="btn-small" href="<?= e($filterUrl(['page' => $page + 1])) ?>"><?= e(admin_trans('common_next')) ?> &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('nav_activity')) ?></h3>
<p><?= e(admin_trans('activity_help')) ?></p>
<ul>
    <li><?= e(admin_trans('activity_retention_help', ['days' => (int) config('activity.retention_days', 180)])) ?></li>
    <li><?= e(admin_trans('activity_actions_help')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'editor', 'section' => 'activity-log'];

include CMS_PATH . '/admin/partials/layout.php';

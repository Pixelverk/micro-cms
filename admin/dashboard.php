<?php

$pageTitle = admin_trans('nav_dashboard');
$username = current_username();

// A per-status tally, computed in one pass over the content table.
$statusCounts = array_fill_keys(content_statuses(), 0);
$statusCounts['trash'] = 0;

$rows = db()->query(
    'SELECT status, deleted_at, COUNT(*) AS total FROM content GROUP BY status, deleted_at'
)->fetchAll();

foreach ($rows as $row) {
    $key = $row['deleted_at'] !== null ? 'trash' : (string) $row['status'];
    $statusCounts[$key] = ($statusCounts[$key] ?? 0) + (int) $row['total'];
}

$totalCount = array_sum($statusCounts) - $statusCounts['trash'];

// The five most recently edited items across every content type, trashed
// excluded. A cross-type query for the dashboard only, so it stays here rather
// than becoming a helper with one caller.
$recentContent = db()->query(
    "SELECT id, type, title, status, updated_at
     FROM content
     WHERE deleted_at IS NULL
     ORDER BY updated_at DESC, id DESC
     LIMIT 5"
)->fetchAll();

// Labels for the type names shown beside each recently edited item.
$contentTypes = theme_config()['content_types'] ?? [];

// Recent activity, for the roles allowed to read the log.
$recentActivity = admin_can('activity.view') ? list_activity([], 5)['items'] : [];

// Last seven days against the seven before it. The comparison is null when
// there is no baseline, which is what analytics_change() already reports.
// Analytics has no capability of its own; every signed-in user can open it,
// so the dashboard reports the same thing.
$weekViews    = analytics_views(7);
$weekVisitors = analytics_unique_visitors(7);
$viewsChange  = analytics_change($weekViews, analytics_views(7, 7));

// page content
ob_start();
?>
<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('dashboard_welcome', ['name' => $username])) ?></h2>
        <p><?= e(admin_trans('dashboard_intro')) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn-primary" href="<?= url('admin/content') ?>"><?= icon('post', 16) ?><?= e(admin_trans('nav_content')) ?></a>
        <a class="btn-secondary" href="<?= url('admin/content/edit') . '?type=' . urlencode((string) (array_key_first(theme_config()['content_types'] ?? []) ?: 'page')) ?>"><?= icon('book', 16) ?><?= e(admin_trans('common_add')) ?></a>
    </div>
</div>

<div class="card-grid">
    <a class="tile" href="<?= url('admin/content') ?>">
        <span class="tile-icon"><?= icon('post', 20) ?></span>
        <span class="tile-body">
            <span class="tile-title"><?= e(admin_trans('nav_content')) ?></span>
            <span class="tile-meta"><?= e(admin_trans('dashboard_content_summary', [
                'total'     => $totalCount,
                'published' => $statusCounts['published'],
                'draft'     => $statusCounts['draft'],
            ])) ?></span>
        </span>
    </a>

    <a class="tile" href="<?= url('admin/media') ?>">
        <span class="tile-icon"><?= icon('media-image', 20) ?></span>
        <span class="tile-body">
            <span class="tile-title"><?= e(admin_trans('nav_media')) ?></span>
            <span class="tile-meta"><?= e(admin_trans('media_intro')) ?></span>
        </span>
    </a>

    <a class="tile" href="<?= url('admin/user') ?>">
        <span class="tile-icon"><?= icon('group', 20) ?></span>
        <span class="tile-body">
            <span class="tile-title"><?= e(admin_trans('nav_users')) ?></span>
            <span class="tile-meta"><?= e(admin_trans('user_intro')) ?></span>
        </span>
    </a>

    <a class="tile" href="<?= url('admin/menu/edit') ?>">
        <span class="tile-icon"><?= icon('menu', 20) ?></span>
        <span class="tile-body">
            <span class="tile-title"><?= e(admin_trans('nav_menus')) ?></span>
            <span class="tile-meta"><?= e(admin_trans('menu_intro')) ?></span>
        </span>
    </a>

    <a class="tile" href="<?= url('admin/settings') ?>">
        <span class="tile-icon"><?= icon('settings', 20) ?></span>
        <span class="tile-body">
            <span class="tile-title"><?= e(admin_trans('nav_settings')) ?></span>
            <span class="tile-meta"><?= e(admin_trans('settings_intro')) ?></span>
        </span>
    </a>

    <a class="tile" href="<?= url('admin/analytics') ?>">
        <span class="tile-icon"><?= icon('clipboard-check', 20) ?></span>
        <span class="tile-body">
            <span class="tile-title"><?= e(admin_trans('nav_analytics')) ?></span>
            <span class="tile-meta"><?= e(admin_trans('analytics_intro')) ?></span>
        </span>
    </a>

    <a class="tile" href="<?= url('admin/activity') ?>">
        <span class="tile-icon"><?= icon('clock', 20) ?></span>
        <span class="tile-body">
            <span class="tile-title"><?= e(admin_trans('nav_activity')) ?></span>
            <span class="tile-meta"><?= e(admin_trans('activity_intro')) ?></span>
        </span>
    </a>

    <a class="tile" href="<?= url() ?>" target="_blank" rel="noopener">
        <span class="tile-icon"><?= icon('open-in-browser', 20) ?></span>
        <span class="tile-body">
            <span class="tile-title"><?= e(admin_trans('dashboard_site_preview')) ?></span>
            <span class="tile-meta"><?= e(admin_trans('dashboard_site_preview_help')) ?></span>
        </span>
    </a>
</div>

<div class="page-sections dashboard-panels">

    <div class="dashboard-columns">

        <div class="card">
            <h3 class="card-title"><?= e(admin_trans('dashboard_recent_content')) ?></h3>
            <?php if (!$recentContent): ?>
                <p class="text-muted"><?= e(admin_trans('dashboard_no_content')) ?></p>
            <?php else: ?>
                <ul class="dashboard-list">
                    <?php foreach ($recentContent as $item): ?>
                        <?php
                        $itemType  = (string) $item['type'];
                        $typeLabel = $contentTypes[$itemType]['label'] ?? ucfirst(str_replace('_', ' ', $itemType));
                        $editUrl   = url('admin/content/edit') . '?type=' . urlencode($itemType) . '&id=' . (int) $item['id'];
                        ?>
                        <li class="dashboard-list-item">
                            <a class="dashboard-list-link" href="<?= e($editUrl) ?>"><?= e($item['title']) ?></a>
                            <span class="dashboard-list-meta">
                                <?= e($typeLabel) ?>
                                <span class="status status-<?= e((string) $item['status']) ?>"><?= e(content_status_label((string) $item['status'])) ?></span>
                                <?= e(format_local_datetime((int) $item['updated_at'], 'Y-m-d H:i')) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3 class="card-title"><?= e(admin_trans('dashboard_traffic')) ?></h3>
            <p class="dashboard-metric"><?= $weekViews ?></p>
            <p class="dashboard-metric-label">
                <?= e(admin_trans('dashboard_stat_views')) ?>
                <?php if ($viewsChange !== null): ?>
                    <span class="<?= $viewsChange >= 0 ? 'delta-up' : 'delta-down' ?>"><?= e(sprintf('%+d%%', (int) round($viewsChange))) ?></span>
                <?php endif; ?>
            </p>
            <p class="dashboard-metric-secondary"><?= e(admin_trans('dashboard_stat_visitors', ['count' => $weekVisitors])) ?></p>
        </div>

        <?php if (admin_can('activity.view')): ?>
            <div class="card">
                <h3 class="card-title"><?= e(admin_trans('dashboard_recent_activity')) ?></h3>
                <?php if (!$recentActivity): ?>
                    <p class="text-muted"><?= e(admin_trans('dashboard_no_activity')) ?></p>
                <?php else: ?>
                    <ul class="dashboard-list">
                        <?php foreach ($recentActivity as $entry): ?>
                            <li class="dashboard-list-item">
                                <span class="dashboard-list-link"><?= e(activity_action_label((string) $entry['action'])) ?></span>
                                <span class="dashboard-list-meta">
                                    <?php if (!empty($entry['summary'])): ?><?= e((string) $entry['summary']) ?><?php endif; ?>
                                    <?= e(format_local_datetime((int) $entry['created_at'], 'Y-m-d H:i')) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php
$content = ob_get_clean();

// page help
ob_start();
?>
<h3><?= e(admin_trans('dashboard_help_title')) ?></h3>
<p><?= e(admin_trans('dashboard_help_intro')) ?></p>
<ul>
    <li><?= e(admin_trans('dashboard_help_content')) ?></li>
    <li><?= e(admin_trans('dashboard_help_users')) ?></li>
    <li><?= e(admin_trans('dashboard_help_site')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';

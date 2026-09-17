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

$trashCount = content_trash_count();
$totalCount = array_sum($statusCounts) - $statusCounts['trash'];

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

<div class="dashboard-strip">
    <span class="dashboard-strip-item">
        <span class="status status-published"><?= (int) $statusCounts['published'] ?></span>
        <?= e(admin_trans('status_published')) ?>
    </span>
    <span class="dashboard-strip-item">
        <span class="status status-draft"><?= (int) $statusCounts['draft'] ?></span>
        <?= e(admin_trans('status_draft')) ?>
    </span>
    <span class="dashboard-strip-item">
        <span class="status status-scheduled"><?= (int) $statusCounts['scheduled'] ?></span>
        <?= e(admin_trans('status_scheduled')) ?>
    </span>
    <?php if ($trashCount > 0): ?>
        <a class="dashboard-strip-item dashboard-strip-link" href="<?= url('admin/content') . '?status=trash' ?>">
            <?= icon('wrench', 16) ?>
            <?= e(admin_trans('trash_title')) ?>: <?= (int) $trashCount ?>
        </a>
    <?php endif; ?>
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

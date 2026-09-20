<?php

$pageTitle = admin_trans('nav_dashboard');
$username  = current_username();

$theme        = theme_config();
$contentTypes = $theme['content_types'] ?? [];
$formTypes    = $theme['form_types'] ?? [];

// A per-type, per-status tally, computed in one pass over the content table.
$counts = [];

$rows = db()->query(
    'SELECT type, status, deleted_at, COUNT(*) AS total FROM content GROUP BY type, status, deleted_at'
)->fetchAll();

foreach ($rows as $row) {
    $key = $row['deleted_at'] !== null ? 'trash' : (string) $row['status'];

    $counts[$row['type']][$key] = ($counts[$row['type']][$key] ?? 0) + (int) $row['total'];
}

// What is still outstanding, per content type. A type with nothing to do drops
// out entirely, so this stays a shortlist of work rather than another menu.
$contentAttention = [];

foreach ($contentTypes as $type => $config) {
    $work  = [];
    $first = '';

    foreach ([
        'draft'     => 'dashboard_attention_drafts',
        'scheduled' => 'dashboard_attention_scheduled',
        'trash'     => 'dashboard_attention_trash',
    ] as $status => $key) {
        $count = $counts[$type][$status] ?? 0;

        if ($count > 0) {
            $work[] = admin_trans($key, ['count' => $count]);
            $first  = $first === '' ? $status : $first;
        }
    }

    if (!$work) {
        continue;
    }

    $contentAttention[] = [
        'label' => (string) ($config['label'] ?? ucfirst((string) $type)) . 's',
        'text'  => implode(' · ', $work),
        'url'   => url('admin/content') . '?type=' . urlencode((string) $type) . '&status=' . $first,
    ];
}

// Submissions that still want an answer. Only a role that can open the inbox
// gets the count: for anyone else the tile would only be a link to a 403.
$canViewForms = admin_can('forms.view');

$newMessages     = ($formTypes && $canViewForms) ? form_submission_count(['status' => 'new']) : 0;
$waitingMessages = ($formTypes && $canViewForms) ? form_submission_count(['status' => 'waiting']) : 0;

$messageWork = [];

if ($newMessages > 0) {
    $messageWork[] = admin_trans('dashboard_attention_new', ['count' => $newMessages]);
}
if ($waitingMessages > 0) {
    $messageWork[] = admin_trans('dashboard_attention_waiting', ['count' => $waitingMessages]);
}

// A health problem is only actionable for the roles that can change settings.
$health          = admin_can('settings.manage') ? health_summary(health_checks()) : null;
$healthNeedsWork = $health !== null && ($health['fail'] > 0 || $health['warn'] > 0);

$healthWork = [];

if ($healthNeedsWork) {
    if ($health['fail'] > 0) {
        $healthWork[] = admin_trans('dashboard_attention_problems', ['count' => $health['fail']]);
    }
    if ($health['warn'] > 0) {
        $healthWork[] = admin_trans('dashboard_attention_warnings', ['count' => $health['warn']]);
    }
}

$hasAttention = $contentAttention || $messageWork || $healthNeedsWork;

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
</div>

<?php if (maintenance_mode_enabled()): ?>
    <div class="notice notice-warning">
        <p>
            <strong><?= e(admin_trans('dashboard_maintenance_title')) ?></strong>
            <?= e(admin_trans('dashboard_maintenance_body')) ?>
            <?php if (admin_can('settings.manage')): ?>
                <a href="<?= e(url('admin/settings')) ?>"><?= e(admin_trans('dashboard_maintenance_link')) ?></a>
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<?php
// Only work that exists is listed: a type with nothing outstanding, an empty
// inbox or a clean health check add no rows at all.
?>
<?php if ($hasAttention): ?>
    <div class="card-grid">
        <?php foreach ($contentAttention as $card): ?>
            <a class="tile" href="<?= e($card['url']) ?>">
                <span class="tile-icon"><?= icon('post', 20) ?></span>
                <span class="tile-body">
                    <span class="tile-title"><?= e($card['label']) ?></span>
                    <span class="tile-meta"><?= e($card['text']) ?></span>
                </span>
            </a>
        <?php endforeach; ?>

        <?php if ($messageWork): ?>
            <a class="tile" href="<?= url('admin/messages') ?>">
                <span class="tile-icon"><?= icon('mail-in', 20) ?></span>
                <span class="tile-body">
                    <span class="tile-title"><?= e(admin_trans('forms_title')) ?></span>
                    <span class="tile-meta"><?= e(implode(' · ', $messageWork)) ?></span>
                </span>
            </a>
        <?php endif; ?>

        <?php if ($healthNeedsWork): ?>
            <a class="tile" href="<?= url('admin/health') ?>">
                <span class="tile-icon"><?= icon('heart-pulse', 20) ?></span>
                <span class="tile-body">
                    <span class="tile-title"><?= e(admin_trans('nav_health')) ?></span>
                    <span class="tile-meta"><?= e(implode(' · ', $healthWork)) ?></span>
                </span>
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <p class="text-muted"><?= e(admin_trans('dashboard_attention_none')) ?></p>
<?php endif; ?>

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

<p class="dashboard-docs">
    <?= e(admin_trans('dashboard_docs_hint')) ?>
    <a href="<?= url('admin/docs') ?>"><?= e(admin_trans('help_read_docs')) ?> &rarr;</a>
</p>

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

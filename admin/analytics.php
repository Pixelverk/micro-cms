<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Analytics
|--------------------------------------------------------------------------
| Read-only traffic report from the page_views table. Charts are inline SVG
| on purpose: the no-build / no-CDN rule rules out a chart library.
|--------------------------------------------------------------------------
*/

$pageTitle = admin_trans('analytics');

// Time horizon, and the window of the same length before it for comparison.
$ranges = [
    30  => admin_trans('analytics_range_30'),
    180 => admin_trans('analytics_range_180'),
    365 => admin_trans('analytics_range_365'),
];

$range = (int) ($_GET['range'] ?? 30);

if (!isset($ranges[$range])) {
    $range = 30;
}

$rangeLabel = $ranges[$range];

$views          = analytics_views($range);
$viewsPrevious  = analytics_views($range, $range);
$visitors       = analytics_unique_visitors($range);
$visitorsPrev   = analytics_unique_visitors($range, $range);
$cacheRatio     = analytics_cache_hit_ratio($range);
$cacheRatioPrev = analytics_cache_hit_ratio($range, $range);

$dailyViews   = analytics_daily_views($range);
$topPages     = analytics_top_pages($range, 10);
$topReferrers = analytics_top_referrers($range, 10);

/**
 * A comparison line: " +12% vs previous 30 days", or a muted note when the
 * previous window has no baseline to compare against.
 */
$comparison = static function (int $current, int $previous) use ($rangeLabel): array {
    $change = analytics_change($current, $previous);

    if ($change === null) {
        return ['text' => admin_trans('analytics_no_previous'), 'class' => 'text-muted'];
    }

    return [
        'text'  => sprintf('%+d%%', (int) round($change)) . ' ' . admin_trans('analytics_vs_previous', ['range' => $rangeLabel]),
        'class' => $change >= 0 ? 'delta-up' : 'delta-down',
    ];
};

$viewsChange    = $comparison($views, $viewsPrevious);
$visitorsChange = $comparison($visitors, $visitorsPrev);

// The cache ratio compares in percentage points, not relative change.
$ratioValue  = $cacheRatio ? number_format($cacheRatio['ratio'] * 100, 0) . '%' : '—';
$ratioChange = ['text' => admin_trans('analytics_no_previous'), 'class' => 'text-muted'];

if ($cacheRatio && $cacheRatioPrev) {
    $points = ($cacheRatio['ratio'] - $cacheRatioPrev['ratio']) * 100;

    $ratioChange = [
        'text'  => sprintf('%+d', (int) round($points)) . ' ' . admin_trans('analytics_points') . ' ' . admin_trans('analytics_vs_previous', ['range' => $rangeLabel]),
        'class' => $points >= 0 ? 'delta-up' : 'delta-down',
    ];
}

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('analytics')) ?></h2>
        <p><?= e(admin_trans('analytics_intro')) ?></p>
    </div>
    <div class="page-actions">
        <div class="status-tabs">
            <?php foreach ($ranges as $days => $label): ?>
                <a class="status-tab <?= $days === $range ? 'active' : '' ?>" href="<?= e(url('admin/analytics') . '?range=' . $days) ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="cards">
    <div class="card stat-card">
        <h2><?= e((string) $views) ?></h2>
        <p><?= e(admin_trans('analytics_views')) ?></p>
        <p class="stat-change <?= e($viewsChange['class']) ?>"><?= e($viewsChange['text']) ?></p>
    </div>

    <div class="card stat-card">
        <h2><?= e((string) $visitors) ?></h2>
        <p><?= e(admin_trans('analytics_unique_visitors')) ?></p>
        <p class="stat-change <?= e($visitorsChange['class']) ?>"><?= e($visitorsChange['text']) ?></p>
    </div>

    <div class="card stat-card">
        <h2><?= e($ratioValue) ?></h2>
        <p><?= e(admin_trans('analytics_cache_ratio')) ?></p>
        <p class="stat-change <?= e($ratioChange['class']) ?>"><?= e($ratioChange['text']) ?></p>
    </div>
</div>

<div class="card">
    <h2><?= e(admin_trans('analytics_daily', ['range' => $rangeLabel])) ?></h2>
    <?= analytics_sparkline(array_values($dailyViews)) ?>
</div>

<div class="stack">
    <div class="card">
        <h2><?= e(admin_trans('analytics_top_pages')) ?></h2>

        <?php if (!$topPages): ?>
            <p class="empty-state"><?= e(admin_trans('analytics_no_data')) ?></p>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th><?= e(admin_trans('analytics_path')) ?></th>
                        <th><?= e(admin_trans('analytics_views')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topPages as $row): ?>
                        <tr>
                            <td><code><?= e($row['path']) ?></code></td>
                            <td><?= e((string) $row['views']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('analytics_top_referrers')) ?></h2>

        <?php if (!$topReferrers): ?>
            <p class="empty-state"><?= e(admin_trans('analytics_no_data')) ?></p>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th><?= e(admin_trans('analytics_referrer')) ?></th>
                        <th><?= e(admin_trans('analytics_views')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topReferrers as $row): ?>
                        <tr>
                            <td><?= e($row['referrer_host']) ?></td>
                            <td><?= e((string) $row['views']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('analytics')) ?></h3>
<p><?= e(admin_trans('analytics_help')) ?></p>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'analytics'];

include CMS_PATH . '/admin/partials/layout.php';

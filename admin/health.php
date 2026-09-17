<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Site health
|--------------------------------------------------------------------------
| A read-only report. It explains what to change but never changes anything
| itself, so it is safe to open on a live site.
|--------------------------------------------------------------------------
*/

$pageTitle = admin_trans('nav_health');

$checks  = health_checks();
$summary = health_summary($checks);

$statusLabels = [
    'ok'   => admin_trans('health_ok'),
    'warn' => admin_trans('health_warning'),
    'fail' => admin_trans('health_problem'),
];

$statusClasses = [
    'ok'   => 'status-published',
    'warn' => 'status-scheduled',
    'fail' => 'status-failed',
];

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('nav_health')) ?></h2>
        <p><?= e(admin_trans('health_intro')) ?></p>
    </div>
</div>

<?php if ($summary['fail'] === 0 && $summary['warn'] === 0): ?>
    <p><span class="status status-published"><?= e(admin_trans('health_all_ok')) ?></span></p>
<?php else: ?>
    <p class="text-muted">
        <?= e(admin_trans('health_summary', ['problems' => $summary['fail'], 'warnings' => $summary['warn']])) ?>
    </p>
<?php endif; ?>

<table class="admin-table">
    <thead>
        <tr>
            <th><?= e(admin_trans('health_check')) ?></th>
            <th><?= e(admin_trans('health_status')) ?></th>
            <th><?= e(admin_trans('health_detail')) ?></th>
            <th><?= e(admin_trans('health_fix')) ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($checks as $check): ?>
            <tr>
                <td><?= e($check['label']) ?></td>
                <td>
                    <span class="status <?= e($statusClasses[$check['status']] ?? 'status-draft') ?>">
                        <?= e($statusLabels[$check['status']] ?? $check['status']) ?>
                    </span>
                </td>
                <td><?= e($check['detail']) ?></td>
                <td><?= e($check['fix']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('nav_health')) ?></h3>
<p><?= e(admin_trans('health_help')) ?></p>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'site-health'];

include CMS_PATH . '/admin/partials/layout.php';

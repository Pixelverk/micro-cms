<?php

$pageTitle = 'Analytics';
$username = current_username();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p>Nothing to see here yet!</p>
    </div>
    <div class="page-actions">

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
$docsLink = ['tab' => 'reference', 'section' => 'maintenance'];


include CMS_PATH . '/admin/partials/layout.php';
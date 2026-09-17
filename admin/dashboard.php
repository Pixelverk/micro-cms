<?php

$pageTitle = 'Dashboard';
$username = current_username();

// page content
ob_start();
?>
<div class="page-header">
    <div class="page-title">
        <h2>Welcome, <?php echo e($username); ?> 👋</h2>
        <p><?= e(admin_trans('dashboard_intro')) ?></p>
    </div>
    <div class="page-actions">
    </div>
</div>

<div class="cards">
    <div class="card">
        <h2><?= e(admin_trans('nav_content')) ?></h2>
        <p><?= e(admin_trans('content_intro')) ?></p>
        <a href="<?= url('admin/content') ?>"><?= e(admin_trans('dashboard_manage_content')) ?></a>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('nav_users')) ?></h2>
        <p><?= e(admin_trans('user_intro')) ?></p>
        <a href="<?= url('admin/user') ?>"><?= e(admin_trans('dashboard_manage_users')) ?></a>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('nav_menus')) ?></h2>
        <p><?= e(admin_trans('menu_intro')) ?></p>
        <a href="<?= url('admin/menu/edit') ?>"><?= e(admin_trans('dashboard_manage_menus')) ?></a>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('nav_settings')) ?></h2>
        <p><?= e(admin_trans('settings_intro')) ?></p>
        <a href="<?= url('admin/settings') ?>"><?= e(admin_trans('dashboard_manage_settings')) ?></a>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('nav_media')) ?></h2>
        <p><?= e(admin_trans('media_intro')) ?></p>
        <a href="<?= url('admin/media') ?>"><?= e(admin_trans('dashboard_manage_media')) ?></a>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('dashboard_site_preview')) ?></h2>
        <p><?= e(admin_trans('dashboard_site_preview_help')) ?></p>
        <a href="<?= url() ?>" target="_blank"><?= e(admin_trans('dashboard_view_site')) ?></a>
    </div>
</div>

<?php
$content = ob_get_clean();

// page help
ob_start();
?>
<h3>Dashboard page</h3>
<p>This screen has links to the CMS features.</p>
<ul>
    <li>Click 'Manage content' to manage your content.</li>
    <li>Click 'Manage users' to manage your users.</li>
    <li>Click 'View site' to open the front-end site in a new tab.</li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';
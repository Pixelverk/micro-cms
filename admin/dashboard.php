<?php

$pageTitle = 'Dashboard';
$username = $_SESSION['user_id'] ?? 'User';

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
        <h2><?= e(admin_trans('content')) ?></h2>
        <p><?= e(admin_trans('content_card_intro')) ?></p>
        <a href="<?= url('admin/content') ?>"><?= e(admin_trans('manage_content')) ?></a>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('users')) ?></h2>
        <p><?= e(admin_trans('users_card_intro')) ?></p>
        <a href="<?= url('admin/user') ?>"><?= e(admin_trans('manage_users')) ?></a>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('menus')) ?></h2>
        <p><?= e(admin_trans('menus_card_intro')) ?></p>
        <a href="<?= url('admin/menu') ?>"><?= e(admin_trans('manage_menus')) ?></a>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('settings')) ?></h2>
        <p><?= e(admin_trans('settings_card_intro')) ?></p>
        <a href="<?= url('admin/settings') ?>"><?= e(admin_trans('manage_settings')) ?></a>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('media')) ?></h2>
        <p><?= e(admin_trans('media_card_intro')) ?></p>
        <a href="<?= url('admin/media') ?>"><?= e(admin_trans('manage_media')) ?></a>
    </div>

    <div class="card">
        <h2><?= e(admin_trans('site_preview')) ?></h2>
        <p><?= e(admin_trans('view_website_new_tab')) ?></p>
        <a href="<?= url() ?>" target="_blank"><?= e(admin_trans('view_site')) ?></a>
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
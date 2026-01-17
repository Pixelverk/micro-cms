<?php

$pageTitle = 'Dashboard';
$username = $_SESSION['user_id'] ?? 'User';

// page content
ob_start();
?>
<div class="page-header">
    <div class="page-title">
        <h2>Welcome, <?php echo e($username); ?> 👋</h2>
        <p>Use the tools below to manage your site.</p>
    </div>
    <div class="page-actions">
    </div>
</div>

<div class="cards">
    <div class="card">
        <h2>Content</h2>
        <p>View, edit, add, or remove content.</p>
        <a href="<?= url('admin/content-list/') ?>">Manage content →</a>
    </div>

    <div class="card">
        <h2>Users</h2>
        <p>Create and manage editor accounts.</p>
        <a href="<?= url('admin/user-list/') ?>">Manage users →</a>
    </div>

    <div class="card">
        <h2>Menus</h2>
        <p>Create and manage menus.</p>
        <a href="<?= url('admin/menu-edit/') ?>">Manage menus →</a>
    </div>

    <div class="card">
        <h2>Settings</h2>
        <p>Edit site and CMS settings.</p>
        <a href="<?= url('admin/site-settings/') ?>">Manage settings →</a>
    </div>

    <div class="card">
        <h2>Site Preview</h2>
        <p>Open the public site in a new tab.</p>
        <a href="<?= url() ?>" target="_blank">View site →</a>
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

include __DIR__ . '/partials/layout.php';
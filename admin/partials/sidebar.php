<?php
// admin/partials/sidebar.php

$currentPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$currentPath = preg_replace('#^admin/?#', '', $currentPath);

$theme = theme_config();
$contentTypes = $theme['content_types'] ?? [];

function is_active(string $path, string $current): string
{
    return str_starts_with($current, $path) ? 'active' : '';
}

function is_content_type_active(string $type): string
{
    $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $page = preg_replace('#^admin/?#', '', $path);

    $contentPages = ['content-list', 'content-edit'];
    $currentType = $_GET['type'] ?? 'page';

    return (in_array($page, $contentPages, true) && $currentType === $type) ? 'active' : '';
}

?>

<nav class="admin-sidebar">

    <div class="sidebar-header">
        <h1><a href="<?= url('admin/dashboard')?>">Micro CMS</a></h1>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-title">Welcome</div>
        
        <a href="<?= url('admin/dashboard') ?>" class="sidebar-link <?= is_active('dashboard', $currentPath) ?> ?>">
            <span class="sidebar-icon"><?= icon('view-grid', 18) ?></span>
            Dashboard
        </a>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-title">Content</div>
        <?php foreach ($contentTypes as $type => $config): ?>
            <a href="<?= url('admin/content-list') . '?type=' . $type ?>"
               class="sidebar-link <?= is_content_type_active($type) ?>" >
               <span class="sidebar-icon"><?= icon('post', 18) ?></span>
               <?= e($config['label'] ?? ucfirst($type)) . 's' ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-title">Menus</div>

        <a href="<?= url('admin/menu-edit') ?>" class="sidebar-link <?= is_active('menu-edit', $currentPath) ?>">
            <span class="sidebar-icon"><?= icon('menu', 18) ?></span>
            Menu List
        </a>

    </div>

    <div class="sidebar-section">
        <div class="sidebar-title">Users</div>

        <a href="<?= url('admin/user-list') ?>" class="sidebar-link <?= is_active('user-list', $currentPath) ?>">
            <span class="sidebar-icon"><?= icon('group', 18) ?></span>
            User List
        </a>

        <a href="<?= url('admin/user-add') ?>" class="sidebar-link <?= is_active('user-add', $currentPath) ?>">
            <span class="sidebar-icon"><?= icon('user-plus', 18) ?></span>
            Add New
        </a>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-title">System</div>

        <a href="<?= url('admin/media') ?>" class="sidebar-link <?= is_active('media', $currentPath) ?>">
            <span class="sidebar-icon"><?= icon('media-image-list', 18) ?></span>
            Media
        </a>

        <a href="<?= url('admin/settings') ?>" class="sidebar-link <?= is_active('settings', $currentPath) ?>">
            <span class="sidebar-icon"><?= icon('settings', 18) ?></span>
            Settings
        </a>
    </div>

    <div class="sidebar-section sidebar-footer">
        <a href="<?= url('admin/logout') ?>" class="sidebar-link danger">
            <span class="sidebar-icon"><?= icon('log-out', 18) ?></span>
            Logout
        </a>
    </div>
</nav>
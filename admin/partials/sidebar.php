<?php
// admin/partials/sidebar.php

$theme = theme_config();

// The current admin page, e.g. "content/edit" (query string stripped).
$currentPath = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$currentPath = (string) preg_replace('#^admin/?#', '', $currentPath);

// The content type shown by the current request (the content list defaults
// to the first type when none is chosen).
$currentType = (string) ($_GET['type'] ?? '');

$contentTypes = $theme['content_types'] ?? [];
if ($currentType === '') {
    $currentType = (string) (array_key_first($contentTypes) ?? 'page');
}

$formTypes = $theme['form_types'] ?? [];

?>

<nav class="sidebar" aria-label="<?= e(admin_trans('nav_aria_main')) ?>">

    <div class="sidebar-header">
        <a class="sidebar-brand" href="<?= url('admin/dashboard') ?>">
            <span class="sidebar-brand-mark" aria-hidden="true">MC</span>
            <span class="sidebar-brand-name">Micro CMS</span>
        </a>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_welcome')) ?></div>
        
        <a href="<?= url('admin/dashboard') ?>" class="sidebar-link <?= admin_nav_active('dashboard', $currentPath) ?>" data-label="Dashboard">
            <span class="sidebar-icon"><?= icon('view-grid', 20) ?></span>
            <?= e(admin_trans('nav_dashboard')) ?>
        </a>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_content')) ?></div>
        <?php foreach ($contentTypes as $type => $config): ?>
            <a href="<?= url('admin/content') . '?type=' . $type ?>"
               class="sidebar-link <?= admin_nav_content_type_active($type, $currentPath, $currentType) ?>"
               data-label="<?= e($config['label']) ?>"
               >
               <span class="sidebar-icon"><?= icon('post', 20) ?></span>
               <?= e($config['label'] ?? ucfirst($type)) . 's' ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (admin_can_open('category') || admin_can_open('tag')): ?>
    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_collections')) ?></div>

        <?php if (admin_can_open('category')): ?>
        <a href="<?= url('admin/category') ?>" class="sidebar-link <?= admin_nav_active('category', $currentPath) ?>" data-label="Categories">
            <span class="sidebar-icon"><?= icon('bookmark-book', 20) ?></span>
            <?= e(admin_trans('nav_categories')) ?>
        </a>
        <?php endif; ?>

        <?php if (admin_can_open('tag')): ?>
        <a href="<?= url('admin/tag') ?>" class="sidebar-link <?= admin_nav_active('tag', $currentPath) ?>" data-label="Tags">
            <span class="sidebar-icon"><?= icon('label', 20) ?></span>
            <?= e(admin_trans('nav_tags')) ?>
        </a>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <?php if (!empty($formTypes) && admin_can_open('messages')): ?>
    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_forms')) ?></div>

        <?php foreach ($formTypes as $type => $config): ?>
            <a href="<?= url('admin/messages') . '?form=' . e($type) ?>"
            class="sidebar-link <?= admin_nav_form_type_active($type, $currentPath, $formTypes) ?>"
            data-label="<?= e($config['label'] ?? ucfirst($type)) ?>"
            >
                <span class="sidebar-icon"><?= icon('mail-in', 20) ?></span>
                <?= e($config['label'] ?? ucfirst($type)) ?>
            </a>
        <?php endforeach; ?>

    </div>
    <?php endif; ?>

    <?php if (admin_can_open('media') || admin_can_open('menu') || admin_can_open('redirects')): ?>
    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_site')) ?></div>

        <?php if (admin_can_open('media')): ?>
        <a href="<?= url('admin/media') ?>" class="sidebar-link <?= admin_nav_active('media', $currentPath) ?>" data-label="Media">
            <span class="sidebar-icon"><?= icon('media-image', 20) ?></span>
            <?= e(admin_trans('nav_media')) ?>
        </a>
        <?php endif; ?>

        <?php if (admin_can_open('menu')): ?>
        <a href="<?= url('admin/menu/edit') ?>" class="sidebar-link <?= admin_nav_active('menu', $currentPath) ?>" data-label="Menus">
            <span class="sidebar-icon"><?= icon('menu', 20) ?></span>
            <?= e(admin_trans('nav_menus')) ?>
        </a>
        <?php endif; ?>

        <?php if (admin_can_open('redirects')): ?>
        <a href="<?= url('admin/redirects') ?>" class="sidebar-link <?= admin_nav_active('redirects', $currentPath) ?>" data-label="<?= e(admin_trans('nav_redirects')) ?>">
            <span class="sidebar-icon"><?= icon('open-in-browser', 20) ?></span>
            <?= e(admin_trans('nav_redirects')) ?>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_reports')) ?></div>

        <a href="<?= url('admin/analytics') ?>" class="sidebar-link <?= admin_nav_active('analytics', $currentPath) ?>" data-label="Analytics">
            <span class="sidebar-icon"><?= icon('clipboard-check', 20) ?></span>
            <?= e(admin_trans('nav_analytics')) ?>
        </a>

        <?php if (admin_can_open('activity')): ?>
        <a href="<?= url('admin/activity') ?>" class="sidebar-link <?= admin_nav_active('activity', $currentPath) ?>" data-label="<?= e(admin_trans('nav_activity')) ?>">
            <span class="sidebar-icon"><?= icon('clock', 20) ?></span>
            <?= e(admin_trans('nav_activity')) ?>
        </a>
        <?php endif; ?>

        <?php if (admin_can_open('health')): ?>
        <a href="<?= url('admin/health') ?>" class="sidebar-link <?= admin_nav_active('health', $currentPath) ?>" data-label="<?= e(admin_trans('nav_health')) ?>">
            <span class="sidebar-icon"><?= icon('heart-pulse', 20) ?></span>
            <?= e(admin_trans('nav_health')) ?>
        </a>
        <?php endif; ?>
    </div>

    <?php if (admin_can_open('settings') || admin_can_open('user') || admin_can_open('utilities')): ?>
    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_system')) ?></div>

        <?php if (admin_can_open('settings')): ?>
        <a href="<?= url('admin/settings') ?>" class="sidebar-link <?= admin_nav_active('settings', $currentPath) ?>" data-label="Settings">
            <span class="sidebar-icon"><?= icon('settings', 20) ?></span>
            <?= e(admin_trans('nav_settings')) ?>
        </a>
        <?php endif; ?>

        <?php if (admin_can_open('user')): ?>
        <a href="<?= url('admin/user') ?>" class="sidebar-link <?= admin_nav_active('user', $currentPath) ?>" data-label="Users">
            <span class="sidebar-icon"><?= icon('group', 20) ?></span>
            <?= e(admin_trans('nav_users')) ?>
        </a>
        <?php endif; ?>

        <?php if (admin_can_open('utilities')): ?>
        <a href="<?= url('admin/utilities') ?>" class="sidebar-link <?= admin_nav_active('utilities', $currentPath) ?>" data-label="Utilities">
            <span class="sidebar-icon"><?= icon('wrench', 20) ?></span>
            <?= e(admin_trans('nav_utilities')) ?>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_help')) ?></div>

        <a href="<?= url('admin/docs') ?>" class="sidebar-link <?= admin_nav_active('docs', $currentPath) ?>" data-label="<?= e(admin_trans('nav_docs')) ?>">
            <span class="sidebar-icon"><?= icon('book', 20) ?></span>
            <?= e(admin_trans('nav_docs')) ?>
        </a>
    </div>

    <div class="sidebar-section sidebar-footer">
        <form method="post" action="<?= url('admin/auth/logout') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="sidebar-link danger sidebar-logout" data-label="Logout">
                <span class="sidebar-icon"><?= icon('log-out', 20) ?></span>
                <?= e(admin_trans('nav_logout')) ?>
            </button>
        </form>
    </div>
</nav>
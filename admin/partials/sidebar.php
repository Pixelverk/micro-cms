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

/**
 * "active" when the current page is, or is below, $path.
 */
function is_active(string $path, string $current): string
{
    return ($current === $path || str_starts_with($current, $path . '/')) ? 'active' : '';
}

/**
 * "active" when browsing (or editing) the given content type.
 */
function is_content_type_active(string $type, string $current, string $currentType): string
{
    if (!in_array($current, ['content', 'content/edit'], true)) {
        return '';
    }

    return $currentType === $type ? 'active' : '';
}

/**
 * "active" when viewing the submissions of the given form type.
 */
function is_form_type_active(string $type, string $current, array $formTypes): string
{
    if ($current !== 'messages') {
        return '';
    }

    // No ?form= means the first form type is being shown.
    $active = $_GET['form'] ?? array_key_first($formTypes);

    return $active === $type ? 'active' : '';
}

?>

<nav class="sidebar">

    <div class="sidebar-header">
        <a class="sidebar-brand" href="<?= url('admin/dashboard') ?>">
            <span class="sidebar-brand-mark" aria-hidden="true">MC</span>
            <span class="sidebar-brand-name">Micro CMS</span>
        </a>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_welcome')) ?></div>
        
        <a href="<?= url('admin/dashboard') ?>" class="sidebar-link <?= is_active('dashboard', $currentPath) ?>" data-label="Dashboard">
            <span class="sidebar-icon"><?= icon('view-grid', 20) ?></span>
            <?= e(admin_trans('nav_dashboard')) ?>
        </a>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_content')) ?></div>
        <?php foreach ($contentTypes as $type => $config): ?>
            <a href="<?= url('admin/content') . '?type=' . $type ?>"
               class="sidebar-link <?= is_content_type_active($type, $currentPath, $currentType) ?>"
               data-label="<?= e($config['label']) ?>"
               >
               <span class="sidebar-icon"><?= icon('post', 20) ?></span>
               <?= e($config['label'] ?? ucfirst($type)) . 's' ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_collections')) ?></div>

        <a href="<?= url('admin/category') ?>" class="sidebar-link <?= is_active('category', $currentPath) ?>" data-label="Categories">
            <span class="sidebar-icon"><?= icon('bookmark-book', 20) ?></span>
            <?= e(admin_trans('nav_categories')) ?>
        </a>

        <a href="<?= url('admin/tag') ?>" class="sidebar-link <?= is_active('tag', $currentPath) ?>" data-label="Tags">
            <span class="sidebar-icon"><?= icon('label', 20) ?></span>
            <?= e(admin_trans('nav_tags')) ?>
        </a>

    </div>

    <?php if (!empty($formTypes)): ?>
    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_forms')) ?></div>

        <?php foreach ($formTypes as $type => $config): ?>
            <a href="<?= url('admin/messages') . '?form=' . e($type) ?>"
            class="sidebar-link <?= is_form_type_active($type, $currentPath, $formTypes) ?>"
            data-label="<?= e($config['label'] ?? ucfirst($type)) ?>"
            >
                <span class="sidebar-icon"><?= icon('mail-in', 20) ?></span>
                <?= e($config['label'] ?? ucfirst($type)) ?>
            </a>
        <?php endforeach; ?>

    </div>
    <?php endif; ?>

    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_more')) ?></div>

        <a href="<?= url('admin/menu/edit') ?>" class="sidebar-link <?= is_active('menu', $currentPath) ?>" data-label="Menus">
            <span class="sidebar-icon"><?= icon('menu', 20) ?></span>
            <?= e(admin_trans('nav_menus')) ?>
        </a>

        <a href="<?= url('admin/media') ?>" class="sidebar-link <?= is_active('media', $currentPath) ?>" data-label="Media">
            <span class="sidebar-icon"><?= icon('media-image', 20) ?></span>
            <?= e(admin_trans('nav_media')) ?>
        </a>

        <a href="<?= url('admin/redirects') ?>" class="sidebar-link <?= is_active('redirects', $currentPath) ?>" data-label="<?= e(admin_trans('nav_redirects')) ?>">
            <span class="sidebar-icon"><?= icon('open-in-browser', 20) ?></span>
            <?= e(admin_trans('nav_redirects')) ?>
        </a>

        <a href="<?= url('admin/activity') ?>" class="sidebar-link <?= is_active('activity', $currentPath) ?>" data-label="<?= e(admin_trans('nav_activity')) ?>">
            <span class="sidebar-icon"><?= icon('clock', 20) ?></span>
            <?= e(admin_trans('nav_activity')) ?>
        </a>

        <a href="<?= url('admin/docs') ?>" class="sidebar-link <?= is_active('docs', $currentPath) ?>" data-label="<?= e(admin_trans('nav_docs')) ?>">
            <span class="sidebar-icon"><?= icon('book', 20) ?></span>
            <?= e(admin_trans('nav_docs')) ?>
        </a>

        <a href="<?= url('admin/analytics') ?>" class="sidebar-link <?= is_active('analytics', $currentPath) ?>" data-label="Analytics">
            <span class="sidebar-icon"><?= icon('clipboard-check', 20) ?></span>
            <?= e(admin_trans('nav_analytics')) ?>
        </a>

    </div>    

    <div class="sidebar-section">
        <div class="sidebar-title"><?= e(admin_trans('nav_system')) ?></div>

        <a href="<?= url('admin/user') ?>" class="sidebar-link <?= is_active('user', $currentPath) ?>" data-label="Users">
            <span class="sidebar-icon"><?= icon('group', 20) ?></span>
            <?= e(admin_trans('nav_users')) ?>
        </a>

        <a href="<?= url('admin/settings') ?>" class="sidebar-link <?= is_active('settings', $currentPath) ?>" data-label="Settings">
            <span class="sidebar-icon"><?= icon('settings', 20) ?></span>
            <?= e(admin_trans('nav_settings')) ?>
        </a>

        <a href="<?= url('admin/utilities') ?>" class="sidebar-link <?= is_active('utilities', $currentPath) ?>" data-label="Utilities">
            <span class="sidebar-icon"><?= icon('wrench', 20) ?></span>
            <?= e(admin_trans('nav_utilities')) ?>
        </a>

        <a href="<?= url('admin/health') ?>" class="sidebar-link <?= is_active('health', $currentPath) ?>" data-label="<?= e(admin_trans('nav_health')) ?>">
            <span class="sidebar-icon"><?= icon('clipboard-check', 20) ?></span>
            <?= e(admin_trans('nav_health')) ?>
        </a>
    </div>

    <div class="sidebar-section sidebar-footer">
        <div class="sidebar-title"><?= e(admin_trans('nav_account')) ?></div>

        <a href="<?= url('admin/profile') ?>" class="sidebar-link" data-label="Profile">
            <span class="sidebar-icon"><?= icon('profile-circle', 20) ?></span>
            <?= e(admin_trans('nav_profile')) ?>
        </a>

        <form method="post" action="<?= url('admin/auth/logout') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="sidebar-link danger" data-label="Logout">
                <span class="sidebar-icon"><?= icon('log-out', 20) ?></span>
                <?= e(admin_trans('nav_logout')) ?>
            </button>
        </form>
    </div>
</nav>
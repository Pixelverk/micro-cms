<?php
/*
|--------------------------------------------------------------------------
| Admin top bar
|--------------------------------------------------------------------------
| The bar shows the current page title, which every page sets as $pageTitle
| before it includes the layout, plus the account block on the right.
*/

// The signed-in editor, for the account block on the right.
$editorName = function_exists('current_username') ? (string) current_username() : '';
$editorInitial = $editorName !== '' ? mb_strtoupper(mb_substr($editorName, 0, 1)) : '?';
?>
<header>
    <div class="header-left">
        <button type="button" id="sidebar-collapse" class="header-icon hide-on-mobile" aria-label="<?= e(admin_trans('nav_aria_collapse')) ?>"><?= icon('sidebar-collapse', 20) ?></button>
        <button type="button" id="sidebar-expand" class="header-icon hide-on-mobile" aria-label="<?= e(admin_trans('nav_aria_expand')) ?>"><?= icon('sidebar-expand', 20) ?></button>
        <button type="button" id="mobile-menu" class="header-icon only-mobile" aria-label="<?= e(admin_trans('nav_aria_menu')) ?>"><?= icon('menu', 20) ?></button>

        <h1 class="header-title"><?= e($pageTitle ?? admin_trans('nav_dashboard')) ?></h1>
    </div>

    <div class="header-right">
        <a href="<?= e(url('')) ?>" target="_blank" rel="noopener" id="visit-site" class="btn-small btn-secondary hide-text-on-mobile">
            <?= icon('open-in-browser', 16) ?>
            <?= e(admin_trans('nav_view_site')) ?>
        </a>

        <?php include __DIR__ . '/help.php'; ?>

        <button type="button" id="light-mode" class="header-icon" aria-label="<?= e(admin_trans('nav_aria_light')) ?>"><?= icon('sun-light', 20) ?></button>
        <button type="button" id="dark-mode" class="header-icon" aria-label="<?= e(admin_trans('nav_aria_dark')) ?>"><?= icon('half-moon', 20) ?></button>
        <button type="button" id="full-screen-expand" class="header-icon hide-on-mobile" aria-label="<?= e(admin_trans('nav_aria_fullscreen')) ?>"><?= icon('expand', 20) ?></button>
        <button type="button" id="full-screen-collapse" class="header-icon hide-on-mobile" aria-label="<?= e(admin_trans('nav_aria_exit_fullscreen')) ?>"><?= icon('collapse', 20) ?></button>

        <a class="header-account" href="<?= url('admin/profile') ?>" aria-label="<?= e(admin_trans('nav_profile')) ?>">
            <span id="user-blob" class="header-avatar" aria-hidden="true"><?= e($editorInitial) ?></span>
            <span class="header-account-name"><?= e($editorName) ?></span>
        </a>
    </div>
</header>

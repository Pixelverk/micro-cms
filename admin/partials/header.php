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
        <?php include __DIR__ . '/help.php'; ?>

        <button type="button" id="light-mode" class="header-icon" aria-label="<?= e(admin_trans('nav_aria_light')) ?>"><?= icon('sun-light', 20) ?></button>
        <button type="button" id="dark-mode" class="header-icon" aria-label="<?= e(admin_trans('nav_aria_dark')) ?>"><?= icon('half-moon', 20) ?></button>

        <a href="<?= e(url('')) ?>" target="_blank" rel="noopener" id="visit-site" class="btn-small btn-secondary hide-text-on-mobile">
            <?= icon('open-in-browser', 16) ?>
            <?= e(admin_trans('nav_view_site')) ?>
        </a>

        <div class="account-wrap">
            <button type="button" id="account-toggle" class="header-account"
                    aria-expanded="false" aria-controls="account-menu"
                    aria-label="<?= e(admin_trans('nav_profile')) ?>">
                <span id="user-blob" class="header-avatar" aria-hidden="true"><?= e($editorInitial) ?></span>
                <span class="header-account-name"><?= e($editorName) ?></span>
            </button>

            <div id="account-menu" class="account-menu" hidden>
                <a class="account-menu-item" href="<?= url('admin/profile') ?>">
                    <?= icon('profile-circle', 16) ?><?= e(admin_trans('nav_profile')) ?>
                </a>

                <form method="post" action="<?= url('admin/auth/logout') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="account-menu-item account-menu-item-danger">
                        <?= icon('log-out', 16) ?><?= e(admin_trans('nav_logout')) ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

<script>
(() => {
    const toggle = document.getElementById('account-toggle');
    const menu = document.getElementById('account-menu');

    if (!toggle || !menu) return;

    const setOpen = open => {
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle.addEventListener('click', () => setOpen(menu.hidden));

    // A click anywhere else, or Escape, closes it.
    document.addEventListener('click', e => {
        if (!menu.contains(e.target) && !toggle.contains(e.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            setOpen(false);
        }
    });
})();
</script>

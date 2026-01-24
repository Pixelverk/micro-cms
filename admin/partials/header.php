<header>
    <div class="header-left">
        <span id="sidebar-collapse" class="header-icon"><?= icon('sidebar-collapse', 26) ?></span>
        <span id="sidebar-expand" class="header-icon"><?= icon('sidebar-expand', 26) ?></span>
        <a href="/" target="_blank" id="visit-site" class="header-icon hide-text-on-mobile">
            <span ><?= icon('open-in-browser', 26) ?></span>
            View Website
        </a>
    </div>

    <div class="header-right">
        <?php include __DIR__ . '/help.php'; ?>
        <span id="lang-pick" class="header-icon"><?= icon('language', 26) ?></span>
        <span id="light-mode" class="header-icon"><?= icon('sun-light', 26) ?></span>
        <span id="dark-mode" class="header-icon"><?= icon('half-moon', 26) ?></span>
        <span id="full-screen-expand" class="header-icon hide-on-mobile"><?= icon('expand', 26) ?></span>
        <span id="full-screen-collapse" class="header-icon hide-on-mobile"><?= icon('collapse', 26) ?></span>
        <a class="hide-on-mobile" href="<?= url('admin/profile') ?>">
            <span id="user-blob" class="header-icon"><?= icon('profile-circle', 26) ?></span>
        </a>
    </div>

</header>

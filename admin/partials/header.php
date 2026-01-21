<header>
    <div class="header-left">
        <span id="sidebar-close" class="header-icon"><?= icon('sidebar-collapse', 28) ?></span>
        <span id="sidebar-open" class="header-icon"><?= icon('sidebar-expand', 28) ?></span>
        <a href="/" target="_blank" class="header-icon">
            <span ><?= icon('open-in-browser', 28) ?></span>
            View Website
        </a>
    </div>
    <div class="header-right">
        <?php include __DIR__ . '/help.php'; ?>
        <span id="lang-pick" class="header-icon"><?= icon('language', 28) ?></span>
        <span id="light-mode" class="header-icon"><?= icon('sun-light', 28) ?></span>
        <span id="dark-mode" class="header-icon"><?= icon('half-moon', 28) ?></span>
        <span id="full-screen" class="header-icon"><?= icon('expand', 28) ?></span>
        <span id="user-blob" class="header-icon"><?= icon('profile-circle', 28) ?></span>
    </div>
</header>

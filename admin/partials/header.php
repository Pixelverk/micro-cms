<header>
    <div class="header-left">
        <span id="sidebar-close" class="header-icon"><?= icon('sidebar-collapse') ?></span>
        <span id="sidebar-open" class="header-icon"><?= icon('sidebar-expand') ?></span>
        <span id="user-blob" class="header-icon"><?= icon('open-in-browser') ?> <a href="/" target="_blank" style="margin-left:0.5rem;color:inherit;"> View Website </a></span>
    </div>
    <div class="header-right">
        <?php include __DIR__ . '/help.php'; ?>
        <span id="lang-pick" class="header-icon"><?= icon('language') ?></span>
        <span id="light-mode" class="header-icon"><?= icon('sun-light') ?></span>
        <span id="dark-mode" class="header-icon"><?= icon('half-moon') ?></span>
        <span id="full-screen" class="header-icon"><?= icon('expand') ?></span>
        <span id="user-blob" class="header-icon"><?= icon('profile-circle') ?></span>
    </div>
</header>

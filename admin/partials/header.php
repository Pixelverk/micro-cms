<?php
/*
|--------------------------------------------------------------------------
| Admin top bar
|--------------------------------------------------------------------------
*/
// "admin/content/edit" -> Content / Edit
$trail = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$trail = (string) preg_replace('#^admin/?#', '', $trail);
$trail = array_values(array_filter(explode('/', $trail), 'strlen'));
$trail = $trail === [] ? ['dashboard'] : $trail;

$crumbs = [];
foreach ($trail as $index => $segment) {
    $label = admin_trans($segment);

    // Fall back to a readable form when no translation key exists.
    if ($label === $segment) {
        $label = ucwords(str_replace(['-', '_'], ' ', $segment));
    }

    $crumbs[] = [
        'label' => $label,
        'href'  => $index === count($trail) - 1 ? null : url('admin/' . implode('/', array_slice($trail, 0, $index + 1))),
    ];
}
?>
<header>
    <div class="header-left">
        <button type="button" id="sidebar-collapse" class="header-icon" aria-label="Collapse sidebar"><?= icon('sidebar-collapse', 26) ?></button>
        <button type="button" id="sidebar-expand" class="header-icon" aria-label="Expand sidebar"><?= icon('sidebar-expand', 26) ?></button>
        <button type="button" id="mobile-menu" class="header-icon only-mobile" aria-label="Open menu"><?= icon('menu', 26) ?></button>

        <nav class="breadcrumb" aria-label="Breadcrumb">
            <?php foreach ($crumbs as $crumb): ?>
                <?php if ($crumb['href'] !== null): ?>
                    <a href="<?= e($crumb['href']) ?>"><?= e($crumb['label']) ?></a>
                    <span class="breadcrumb-sep" aria-hidden="true">/</span>
                <?php else: ?>
                    <span aria-current="page"><?= e($crumb['label']) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="header-right">
        <a href="<?= e(url('')) ?>" target="_blank" rel="noopener" id="visit-site" class="header-icon hide-text-on-mobile">
            <span><?= icon('open-in-browser', 26) ?></span>
            <?= e(admin_trans('view_website')) ?>
        </a>

        <?php include __DIR__ . '/help.php'; ?>

        <button type="button" id="light-mode" class="header-icon" aria-label="Light mode"><?= icon('sun-light', 26) ?></button>
        <button type="button" id="dark-mode" class="header-icon" aria-label="Dark mode"><?= icon('half-moon', 26) ?></button>
        <button type="button" id="full-screen-expand" class="header-icon hide-on-mobile" aria-label="Full screen"><?= icon('expand', 26) ?></button>
        <button type="button" id="full-screen-collapse" class="header-icon hide-on-mobile" aria-label="Exit full screen"><?= icon('collapse', 26) ?></button>

        <a class="hide-on-mobile" href="<?= url('admin/profile') ?>" aria-label="<?= e(admin_trans('profile')) ?>">
            <span id="user-blob" class="header-icon"><?= icon('profile-circle', 26) ?></span>
        </a>
    </div>
</header>

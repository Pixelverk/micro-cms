<?php
// theme/components/site-header.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Site Header',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'menu' => [
        'type' => 'menu',
        'label' => 'Menu slot: site-header',
        'default' => 'main'
    ]
],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'none', // 'any', 'none', or 'some'
'allowed_children' => [], // only used if children='some'

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => <<<CSS
@media (min-width: 992px) {
    .site-header-menu .dropdown-menu .dropdown-menu {
        top: -0.5rem;
        left: 100%;
        margin-left: 0.1rem;
    }
}

@media (max-width: 991.98px) {
    .site-header-menu .dropdown-menu .dropdown-menu {
        position: static;
        margin-left: 1rem;
        border: 0;
    }
}
.navbar-search { display: flex; margin-left: auto; margin-right: 0.75rem; }
.navbar-search input[type="search"] {
    width: 12rem;
    padding: 0.35rem 0.6rem;
    border: 1px solid rgba(255, 255, 255, 0.35);
    border-radius: var(--theme-radius);
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
    font: inherit;
    font-size: 0.9rem;
}
.navbar-search input::placeholder { color: rgba(255, 255, 255, 0.6); }
@media (max-width: 991.98px) { .navbar-search { margin-left: 0; } .navbar-search input[type="search"] { width: 100%; } }
CSS,

/** --------------------------------------------
 * Component JS  (optional)
 * -------------------------------------------- */
'js' => <<<JS
const currentPage = window.location.pathname;
document.querySelectorAll('nav a').forEach(link => {
    if (link.getAttribute('href') === currentPage) {
        link.style.fontWeight = '700';
    }
});
JS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, $page) {
    $id = 'header-' . uniqid();
    extract($props, EXTR_SKIP);

    $menu = get_menu_for_location('main');

    $renderItems = function (array $items, bool $nested = false) use (&$renderItems): void {
        foreach ($items as $index => $item) {
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            $hasChildren = $children !== [];
            $itemId = 'menu-item-' . uniqid() . '-' . $index;
            $href = ($item['type'] ?? 'url') === 'page'
                ? url($item['slug'] ?? '')
                : ($item['slug'] ?? '#');
            $classes = $nested ? 'dropdown-submenu' : 'nav-item';
            if ($hasChildren) $classes .= ' dropdown';
            ?>
            <li class="<?= e($classes) ?>">
                <a class="<?= $nested ? 'dropdown-item' : 'nav-link' ?><?= $hasChildren ? ' dropdown-toggle' : '' ?>"
                   href="<?= e($href) ?>"
                   target="<?= e($item['target'] ?? '_self') ?>"
                   <?php if ($hasChildren): ?>id="<?= e($itemId) ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false"<?php endif; ?>>
                    <?= e($item['label'] ?? '') ?>
                </a>
                <?php if ($hasChildren): ?>
                    <ul class="dropdown-menu"<?= $nested ? ' aria-labelledby="' . e($itemId) . '"' : '' ?>>
                        <?php $renderItems($children, true); ?>
                    </ul>
                <?php endif; ?>
            </li>
            <?php
        }
    };
    ?>
    <header id="<?= $id ?>" class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container px-5">
            <a href="<?= url() ?>" class="navbar-brand">Start Bootstrap</a>
            <form class="navbar-search" method="get" action="<?= e(url('search')) ?>" role="search">
                <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>"
                       placeholder="Search…" aria-label="Search this site">
            </form>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
            <nav class="collapse navbar-collapse site-header-menu" id="navbarSupportedContent">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <?php $renderItems($menu['items']); ?>
                </ul>
            </nav>
        </div>
    </header>
    <?php
},
];
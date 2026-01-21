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
header {
    background: #ffffff;
    border-bottom: 1px solid #eaeaea;
}

.logo {
    font-size: 1.25rem;
    font-weight: 700;
    color: #222;
    text-decoration: none;
}

nav a {
    margin-left: 1.5rem;
    text-decoration: none;
    color: #555;
    font-weight: 500;
}

nav a:hover {
    color: #000;
}
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
'render' => function (array $props) {
    $id = 'header-' . uniqid();
    $menu = get_menu('header1');
    extract($props, EXTR_SKIP);
    ?>
    <header id="<?= $id ?>">
        <div class="inner">
            <a href="<?= url() ?>" class="logo">Acme Consulting</a>
            <nav class="site-nav">
                <?php foreach ($menu['items'] as $item): ?>
                    <a href="<?= e($item['type'] === 'page' ? url($item['slug']) : $item['slug']) ?>" target="<?= e($item['target'] ?? '_self') ?>"><?= e($item['label']) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>
    <?php
},
];
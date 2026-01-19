<?php
// theme/components/site-footer.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Site Footer',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'menu' => [
        'type' => 'menu',
        'label' => 'Menu slot: site-footer',
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
footer {
    background: #111;
    color: #ccc;
}

.top {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin: 1rem 0;
}

.brand {
    font-weight: 600;
    color: #fff;
}

footer nav a {
    margin-right: 1.25rem;
    text-decoration: none;
    color: #ccc;
    font-size: 0.95rem;
}

footer nav a:hover {
    color: #fff;
}

.bottom {
    font-size: 0.85rem;
    color: #888;
    border-top: 1px solid #222;
    padding-top: 1rem;
}

footer .inner {
    display:block;
}
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props) {
    $id = 'footer-' . uniqid();
    $year = date('Y');
    extract($props, EXTR_SKIP);

    ?>
    <footer id="<?= $id ?>">
        <div class="inner">
            <div class="top">
                <div class="brand">Acme Consulting</div>
                <nav>
                <a href="<?= url() ?>">Home</a>
                <a href="<?= url('services') ?>">Services</a>
                <a href="<?= url('contact') ?>">Contact</a>
                </nav>
            </div>

            <div class="bottom">
                © <?= $year ?> Acme Consulting. All rights reserved.
            </div>
        </div>
    </footer>
    <?php
},
];
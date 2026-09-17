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
/*
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
*/
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, $page) {
    $id = 'footer-' . uniqid();
    $year = date('Y');
    $menu = get_menu_for_location('footer');
    extract($props, EXTR_SKIP);

    ?>
    <footer id="<?= $id ?>" class="bg-dark py-4 mt-auto">
        <div class="container px-5">
            <div class="row align-items-center justify-content-between flex-column flex-sm-row">
                <div class="col-auto">
                    <div class="small m-0 text-white">Copyright &copy; Your Website <?= $year ?></div>
                </div>
                <div class="col-auto">
                    <?php foreach ($menu['items'] as $index => $item): ?>
                        <?php if ($index > 0): ?><span class="text-white mx-1">&middot;</span><?php endif; ?>
                        <?php $href = ($item['type'] ?? 'url') === 'page' ? url($item['slug'] ?? '') : ($item['slug'] ?? '#'); ?>
                        <a class="link-light small" href="<?= e($href) ?>" target="<?= e($item['target'] ?? '_self') ?>"><?= e($item['label'] ?? '') ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </footer>
    <?php
},
];
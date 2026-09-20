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
    // The slot is one of the menu_locations declared in theme.php; the editor
    // lists them, so a theme author can point this footer at any slot.
    'menu' => [
        'type' => 'select',
        'label' => 'Menu slot',
        'default' => 'footer',
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
    extract($props, EXTR_SKIP);
    $menu = get_menu_for_location((string) ($props['menu'] ?? 'footer'));

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
                        <?php $href = (string) ($item['url'] ?? ''); ?>
                        <?php if ($href === ''): ?>
                            <?php /* A link that no longer resolves is text, not a 404. */ ?>
                            <span class="link-light small"><?= e($item['label'] ?? '') ?></span>
                        <?php else: ?>
                            <a class="link-light small<?= !empty($item['active']) ? ' active' : '' ?>"
                               href="<?= e($href) ?>"
                               target="<?= e($item['target'] ?? '_self') ?>"
                               <?php if (!empty($item['current'])): ?>aria-current="page"<?php endif; ?>><?= e($item['label'] ?? '') ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </footer>
    <?php
},
];
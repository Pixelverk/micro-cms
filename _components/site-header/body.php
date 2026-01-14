<?php
// _components/site-header/body.php

return [
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
     * Render function
     * -------------------------------------------- */
    'render' => function (array $props) {
        $id = 'header-' . uniqid();
        $menu = get_menu_items('header1');
        $homePageSlug = get_setting('homepage_slug');
        extract($props, EXTR_SKIP);

        ?>
        <header id="<?= $id ?>">
            <div class="inner">
                <a href="<?= url() ?>" class="logo">Acme Consulting</a>
                <nav class="site-nav">
                    <?php foreach ($menu as $item):
                        if($item['slug'] === $homePageSlug) {
                            $item['slug'] = '/';
                        }
                    ?>
                        <a href="<?= e($item['type'] === 'page' ? url($item['slug']) : $item['url']) ?>" target="<?= e($item['target'] ?? '_self') ?>"><?= e($item['label']) ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </header>
        <?php
    },
];


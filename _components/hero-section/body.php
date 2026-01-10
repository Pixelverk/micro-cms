<?php
// _components/hero-section/body.php

return [
    /** --------------------------------------------
     * CMS-Editable Schema
     * -------------------------------------------- */
    'schema' => [
        'title' => [
            'type' => 'string',
            'label' => 'Hero Title',
            'required' => true,
            'default' => 'Default Title'
        ],
        'subtitle' => [
            'type' => 'string',
            'label' => 'Hero subtitle',
            'required' => true,
            'default' => 'Default Text'
        ],
        'image' => [
            'type' => 'string',
            'label' => 'Hero Image',
            'required' => false,
            'default' => 'placeholder.png'
        ]
    ],

    /** --------------------------------------------
     * Render function
     * -------------------------------------------- */
    'render' => function (array $props, array &$collectedJs = [], array &$collectedCss = []) {
        $id = 'hero-' . uniqid();

        extract($props, EXTR_SKIP);

        ob_start();
        ?>
        <section id="<?= $id ?>" class="hero">
            <div class="inner">
                <div class="hero-text">
                    <h1><?= e($title)?></h1>
                    <p><?= e($subtitle)?></p>
                </div>
                <div class="hero-img">
                    <img src="<?= url('_assets/img/' . $image) ?>" alt="<?= e($title) ?>">
                </div>
                <?php if (!empty($children)) { renderComponents($children, $collectedJs, $collectedCss); } ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    },
];


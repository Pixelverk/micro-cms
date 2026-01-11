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
     * Child element options
     * -------------------------------------------- */
    'children' => 'none', // 'any', 'none', or 'some'
    'allowed_children' => [], // only used if children='some'

    /** --------------------------------------------
     * Render function
     * -------------------------------------------- */
    'render' => function (array $props, array &$collectedJs = [], array &$collectedCss = []) {
        $id = 'hero-' . uniqid();

        extract($props, EXTR_SKIP);

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
            </div>
        </section>
        <?php
    },
];


<?php
// _components/feature-card/body.php

return [
    /** --------------------------------------------
     * CMS-Editable Schema
     * -------------------------------------------- */
    'schema' => [
        'title' => [
            'type' => 'string',
            'label' => 'Feature Title',
            'required' => true,
            'default' => 'Default Title'
        ],
        'text' => [
            'type' => 'string',
            'label' => 'Feature Text',
            'required' => true,
            'default' => 'Default Text'
        ],
        'icon' => [
            'type' => 'string',
            'label' => 'Feature Icon',
            'required' => false,
            'default' => '👋'
        ],
        'image' => [
            'type' => 'string',
            'label' => 'Feature Image',
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
        $id = 'feature-' . uniqid();

        extract($props, EXTR_SKIP);

        ?>
        <div class="card">
            <img src="<?= url('_assets/img/' . $image) ?>" alt="<?= e($title) ?>">
            <div class="icon"><?= e($icon ?? '#')?></div>
            <h2><?= e($title)?></h2>
            <p><?= e($text)?></p>
        </div>
        <?php
    },
];
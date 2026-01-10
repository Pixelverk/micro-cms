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
            'label' => 'Feature Image',
            'required' => false,
            'default' => 'placeholder.png'
        ],
        'image' => [
            'type' => 'string',
            'label' => 'Feature Image',
            'required' => false,
            'default' => 'placeholder.png'
        ]
    ],

    /** --------------------------------------------
     * Render function
     * -------------------------------------------- */
    'render' => function (array $props, array &$collectedJs = [], array &$collectedCss = []) {
        $id = 'feature-' . uniqid();

        extract($props, EXTR_SKIP);

        ob_start();
        ?>
        <div class="card">
            <img src="/_assets/img/<?= e($image ?? '#')?>" alt="<?= e($title)?>">
            <div class="icon"><?= e($icon ?? '#')?></div>
            <h2><?= e($title)?></h2>
            <p><?= e($text)?></p>
            <?php if (!empty($children)) { renderComponents($children, $collectedJs, $collectedCss); } ?>
        </div>
        <?php
        return ob_get_clean();
    },
];
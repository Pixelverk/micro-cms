<?php
// _components/cta-section/body.php

return [
    /** --------------------------------------------
     * CMS-Editable Schema
     * -------------------------------------------- */
    'schema' => [
        'title' => [
            'type' => 'string',
            'label' => 'CTA Title',
            'required' => true,
            'default' => 'Default Title'
        ],
        'text' => [
            'type' => 'string',
            'label' => 'CTA Text',
            'required' => true,
            'default' => 'Default Text'
        ],
        'url' => [
            'type' => 'string',
            'label' => 'CTA URL',
            'required' => true,
            'default' => '#'
        ],
        'linktext' => [
            'type' => 'string',
            'label' => 'CTA Link Text',
            'required' => true,
            'default' => 'Click Me'
        ]
    ],

    /** --------------------------------------------
     * Child element options
     * -------------------------------------------- */
    'children' => 'any', // 'any', 'none', or 'some'
    'allowed_children' => [], // only used if children='some'

    /** --------------------------------------------
     * Render function
     * -------------------------------------------- */
    'render' => function (array $props, array &$collectedJs = [], array &$collectedCss = []) {
        $id = 'cta-' . uniqid();

        extract($props, EXTR_SKIP);

        ?>
        <section id="<?= $id ?>" class="cta">
            <div class="inner">
                <h1><?= e($title) ?></h1>
                <p><?= e($text) ?></p>
                <a href="<?= url($url) ?>" class="cta-button">
                    <?= e($linktext) ?>
                </a>
                <?php if (!empty($children)) { renderComponents($children, $collectedJs, $collectedCss); } ?>
            </div>
        </section>
        <?php
    },
];
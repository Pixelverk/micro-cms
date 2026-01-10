<?php
// _components/features-section/body.php

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
        ]
    ],

    /** --------------------------------------------
     * Render function
     * -------------------------------------------- */
    'render' => function (array $props, array &$collectedJs = [], array &$collectedCss = []) {
        $id = 'features-' . uniqid();

        extract($props, EXTR_SKIP);

        ob_start();
        ?>
        <section id="<?= $id ?>" class="features">
            <div class="inner grid">
                <?php if (!empty($children)) { renderComponents($children, $collectedJs, $collectedCss); } ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    },
];
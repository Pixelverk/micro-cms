<?php
// _components/features-section/body.php

return [
    /** --------------------------------------------
     * CMS-Editable Schema
     * -------------------------------------------- */
    'schema' => [],

    /** --------------------------------------------
     * Child element options
     * -------------------------------------------- */
    'children' => 'some', // 'any', 'none', or 'some'
    'allowed_children' => ['feature-card'], // only used if children='some'

    /** --------------------------------------------
     * Render function
     * -------------------------------------------- */
    'render' => function (array $props, array &$collectedJs = [], array &$collectedCss = []) {
        $id = 'features-' . uniqid();

        extract($props, EXTR_SKIP);

        ?>
        <section id="<?= $id ?>" class="features">
            <div class="inner grid">
                <?php if (!empty($children)) { renderComponents($children, $collectedJs, $collectedCss); } ?>
            </div>
        </section>
        <?php
    },
];
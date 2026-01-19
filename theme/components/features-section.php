<?php
// theme/components/features-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Feature Section',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'some', // 'any', 'none', or 'some'
'allowed_children' => ['feature-card'],

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => <<<CSS
.features {
    display: block;
    padding: 4rem 2rem;
    background: #f7f7f7;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 2rem;
}
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'features-' . uniqid();

    extract($props, EXTR_SKIP);

    ?>
    <section id="<?= $id ?>" class="features">
        <div class="inner grid">
            <?php if (!empty($children)) { render_components($children, $collectedJs, $collectedCss); } ?>
        </div>
    </section>
    <?php
},

];
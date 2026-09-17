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
'schema' => [
    'title' => [
        'type' => 'string',
        'label' => 'Features left title',
        'required' => true,
        'default' => 'Default Title'
    ],
],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'some', // 'any', 'none', or 'some'
'allowed_children' => ['feature-card'],

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => <<<CSS
/*
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
*/
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'features-' . uniqid();

    extract($props, EXTR_SKIP);

    ?>
    <section class="py-5" id="<?= $id ?>">
        <div class="container px-5 my-5">
            <div class="row gx-5">
                <div class="col-lg-4 mb-5 mb-lg-0"><h2 class="fw-bolder mb-0"><?= e($title)?></h2></div>
                <div class="col-lg-8">
                    <div class="row gx-5 row-cols-1 row-cols-md-2">
                        <?php if (!empty($children)) { render_components($children, $page, $collectedJs, $collectedCss); } ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php
},

];
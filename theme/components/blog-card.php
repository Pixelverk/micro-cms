<?php
// theme/components/blog-card.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Blog Card',

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
        'default' => 'Some quick example text to build on the card title and make up the bulk of the card content'
    ],
    'img' => [
        'type' => 'string',
        'label' => 'Blog image',
        'required' => false,
        'default' => '600x350.png'
    ],
    'img2' => [
        'type' => 'string',
        'label' => 'Author image',
        'required' => false,
        'default' => '40x40.png'
    ]
],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'none', // 'any', 'none', or 'some'
'allowed_children' => [], // only used if children='some'

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => <<<CSS
/*
*/
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'blog-card-' . uniqid();

    extract($props, EXTR_SKIP);

    ?>
    <div id="<?= $id ?>" class="col-lg-4 mb-5">
        <div class="card h-100 shadow border-0">
            <img class="card-img-top" src="<?= img($img)?>" alt="..." />
            <div class="card-body p-4">
                <div class="badge bg-primary bg-gradient rounded-pill mb-2">News</div>
                <a class="text-decoration-none link-dark stretched-link" href="#!"><h5 class="card-title mb-3"><?= e($title)?></h5></a>
                <p class="card-text mb-0"><?= e($text)?></p>
            </div>
            <div class="card-footer p-4 pt-0 bg-transparent border-top-0">
                <div class="d-flex align-items-end justify-content-between">
                    <div class="d-flex align-items-center">
                        <img class="rounded-circle me-3" src="<?= img($img2)?>" alt="..." />
                        <div class="small">
                            <div class="fw-bold">Kelly Rowan</div>
                            <div class="text-muted">March 12, 2023 &middot; 6 min read</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
},

];
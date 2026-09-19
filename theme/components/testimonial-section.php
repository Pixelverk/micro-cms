<?php
// theme/components/testimonial-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Testimonial Section',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'quote' => [
        'type' => 'text',
        'label' => 'The quote',
        'required' => true,
        'default' => 'Default quote text'
    ],
    'name' => [
        'type' => 'text',
        'label' => 'Name',
        'required' => true,
        'default' => 'Mr testimonial'
    ],
    'job' => [
        'type' => 'text',
        'label' => 'Job title',
        'required' => false,
        'default' => 'Good guy'
    ],
    'img' => [
        'type' => 'image',
        'label' => 'Quote Image',
        'required' => false,
        'default' => '40x40.png'
    ]
],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'none', // 'any', 'none', or 'some'
'allowed_children' => [],

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => '',

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'testomonial-' . uniqid();

    extract($props, EXTR_SKIP);

    ?>
    <section id="<?= $id ?>" class="py-5 bg-light">
        <div class="container px-5 my-5">
            <div class="row gx-5 justify-content-center">
                <div class="col-lg-10 col-xl-7">
                    <div class="text-center">
                        <div class="fs-4 mb-4 fst-italic">"<?= e($quote)?>"</div>
                        <div class="d-flex align-items-center justify-content-center">
                            <?= render_image($img ?? '', ['class' => 'rounded-circle me-3', 'alt' => $name ?? '']) ?>
                            <div class="fw-bold">
                                <?= e($name)?>
                                <span class="fw-bold text-primary mx-1">/</span>
                                <?= e($job)?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php
},

];
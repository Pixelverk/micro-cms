<?php
// theme/components/blog-preview-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Blog preview Section',
'description' => "A centred title and intro above three post previews.",

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'title' => [
        'type' => 'string',
        'label' => 'Blog preview title',
        'required' => true,
        'default' => 'Default Title'
    ],
    'subTitle' => [
        'type' => 'string',
        'label' => 'Blog preview subtitle',
        'required' => false,
        'default' => 'Default subtitle'
    ],
],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'some', // 'any', 'none', or 'some'
'allowed_children' => ['blog-card'],

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => '',

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'blog-preview-' . uniqid();

    extract($props, EXTR_SKIP);

    ?>
    <section id="<?= $id ?>" class="py-5">
        <div class="container px-5 my-5">
            <div class="row gx-5 justify-content-center">
                <div class="col-lg-8 col-xl-6">
                    <div class="text-center">
                        <h2 class="fw-bolder"><?= e($title)?></h2>
                        <p class="lead fw-normal text-muted mb-5"><?= e($subTitle)?></p>
                    </div>
                </div>
            </div>
            <div class="row gx-5">
                <?php if (!empty($children)) { render_components($children, $page, $collectedJs, $collectedCss); } ?>
            </div>
            <!-- Call to action-->
            <aside class="bg-primary bg-gradient rounded-3 p-4 p-sm-5 mt-5">
                <div class="d-flex align-items-center justify-content-between flex-column flex-xl-row text-center text-xl-start">
                    <div class="mb-4 mb-xl-0">
                        <div class="fs-3 fw-bold text-white">New products, delivered to you.</div>
                        <div class="text-white-50">Sign up for our newsletter for the latest updates.</div>
                    </div>
                    <div class="ms-xl-4">
                        <?php
                        // The same form the contact section renders, in its inline
                        // variant: it posts the manifest's newsletter type, so the
                        // submission is validated and stored like any other.
                        $form = [
                            'type'    => 'newsletter',
                            'variant' => 'inline',
                            'id'      => $id,
                            'submit'  => 'Sign up',
                            'success' => 'Thanks for signing up!',
                            'error'   => 'Something went wrong. Please try again later.',
                            'page_id' => (int) ($props['_page_id'] ?? 0),
                        ];

                        require theme('partials/form.php');
                        ?>
                    </div>
                </div>
            </aside>
        </div>
    </section>
    <?php
},

];
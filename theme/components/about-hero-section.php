<?php
// theme/components/about-hero-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'About Hero Section',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'title' => [
        'type' => 'text',
        'label' => 'Hero Title',
        'required' => true,
        'default' => 'Our mission is to make building websites easier for everyone.'
    ],
    'subtitle' => [
        'type' => 'text',
        'label' => 'Hero Subtitle',
        'required' => true,
        'default' => 'Start Bootstrap was built on the idea that quality, functional website templates and themes should be available to everyone.'
    ],
    'button_url' => [
        'type' => 'text',
        'label' => 'Button URL',
        'required' => false,
        'default' => '#scroll-target'
    ],
    'button_text' => [
        'type' => 'text',
        'label' => 'Button Text',
        'required' => false,
        'default' => 'Read our story'
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
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'about-hero-' . uniqid();

    extract($props, EXTR_SKIP);

    ?>
    <div class="container px-5 py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-xxl-6">
                <div class="text-center my-5">
                    <h1 class="fw-bolder mb-3"><?= e($title) ?></h1>
                    <p class="lead fw-normal text-muted mb-4"><?= e($subtitle) ?></p>
                    <?php if (!empty($button_url) && !empty($button_text)): ?>
                        <a class="btn btn-primary btn-lg" href="<?= e($button_url) ?>"><?= e($button_text) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
},

];
<?php
// theme/components/about-feature-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * --------------------------------------------
 */
'label' => 'About Feature Section',

/** --------------------------------------------
 * CMS-Editable Schema
 * --------------------------------------------
 */
'schema' => [
    'title' => [
        'type' => 'text',
        'label' => 'Section Title',
        'required' => true,
        'default' => 'Our founding'
    ],
    'text' => [
        'type' => 'textarea',
        'label' => 'Section Text',
        'required' => true,
        'default' => 'Tell visitors how your organization got started and what continues to guide its work.'
    ],
    'image' => [
        'type' => 'text',
        'label' => 'Section Image',
        'required' => true,
        'default' => '600x400.png'
    ],
    'image_alt' => [
        'type' => 'text',
        'label' => 'Image Description',
        'required' => false,
        'default' => ''
    ],
    'image_position' => [
        'type' => 'select',
        'label' => 'Image Position',
        'required' => true,
        'options' => [
            'left' => 'Left',
            'right' => 'Right',
        ],
        'default' => 'left'
    ],
    'background' => [
        'type' => 'select',
        'label' => 'Background',
        'required' => true,
        'options' => [
            'white' => 'White',
            'light' => 'Light',
        ],
        'default' => 'light'
    ],
],

/** --------------------------------------------
 * Child element options
 * --------------------------------------------
 */
'children' => 'none',
'allowed_children' => [],

/** --------------------------------------------
 * Component CSS (optional)
 * --------------------------------------------
 */
'css' => '',

/** --------------------------------------------
 * Render function
 * --------------------------------------------
 */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'about-feature-' . uniqid();

    $title = $props['title'] ?? '';
    $text = $props['text'] ?? '';
    $image = $props['image'] ?? '';
    $imageAlt = $props['image_alt'] ?? $title;
    $imagePosition = ($props['image_position'] ?? 'left') === 'right' ? 'right' : 'left';
    $background = ($props['background'] ?? 'white') === 'light' ? 'bg-light' : '';
    $imageColumnClass = $imagePosition === 'right' ? 'order-first order-lg-last' : '';
    ?>
    <section id="<?= e($id) ?>" class="py-5 <?= e($background) ?>">
        <div class="container px-5 my-5">
            <div class="row gx-5 align-items-center">
                <div class="col-lg-6 <?= e($imageColumnClass) ?>">
                    <img class="img-fluid rounded mb-5 mb-lg-0" src="<?= e(img($image)) ?>" alt="<?= e($imageAlt) ?>" />
                </div>
                <div class="col-lg-6">
                    <h2 class="fw-bolder"><?= e($title) ?></h2>
                    <p class="lead fw-normal text-muted mb-0"><?= e($text) ?></p>
                </div>
            </div>
        </div>
    </section>
    <?php
},

];
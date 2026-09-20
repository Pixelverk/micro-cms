<?php
// theme/components/team-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * --------------------------------------------
 */
'label' => 'Team Section',
'description' => "A row of team members under a heading.",

/** --------------------------------------------
 * CMS-Editable Schema
 * --------------------------------------------
 */
'schema' => [
    'title' => [
        'type' => 'text',
        'label' => 'Section Title',
        'required' => true,
        'default' => 'Our team'
    ],
    'subtitle' => [
        'type' => 'text',
        'label' => 'Section Subtitle',
        'required' => false,
        'default' => 'Dedicated to quality and your success'
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
'children' => 'some',
'allowed_children' => ['team-member'],

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
    $id = 'team-' . uniqid();
    $background = ($props['background'] ?? 'white') === 'light' ? 'bg-light' : '';
    ?>
    <section id="<?= e($id) ?>" class="py-5 <?= e($background) ?>">
        <div class="container px-5 my-5">
            <div class="text-center">
                <h2 class="fw-bolder"><?= e($props['title'] ?? '') ?></h2>
                <?php if (!empty($props['subtitle'])): ?>
                    <p class="lead fw-normal text-muted mb-5"><?= e($props['subtitle']) ?></p>
                <?php endif; ?>
            </div>
            <div class="row gx-5 row-cols-1 row-cols-sm-2 row-cols-xl-4 justify-content-center">
                <?php if (!empty($props['children'])): ?>
                    <?php render_components($props['children'], $page, $collectedJs, $collectedCss); ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
},

];
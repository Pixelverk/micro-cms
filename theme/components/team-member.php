<?php
// theme/components/team-member.php

return [

'label' => 'Team Member',

'schema' => [
    'name' => [
        'type' => 'text',
        'label' => 'Name',
        'required' => true,
        'default' => 'Team Member'
    ],
    'role' => [
        'type' => 'text',
        'label' => 'Role',
        'required' => true,
        'default' => 'Role'
    ],
    'image' => [
        'type' => 'image',
        'label' => 'Portrait',
        'required' => true,
        'default' => ':placeholder'
    ],
    'image_alt' => [
        'type' => 'text',
        'label' => 'Image Description',
        'required' => false,
        'default' => ''
    ],
],

'children' => 'none',
'allowed_children' => [],
'css' => '',

'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $imageValue = (string) ($props['image'] ?? '');
    $imageAlt = trim((string) ($props['image_alt'] ?? ''));

    // A library image carries its own alt text; a theme filename has none, so
    // fall back to the visible name.
    if ($imageAlt === '' && !ctype_digit($imageValue)) {
        $imageAlt = (string) ($props['name'] ?? '');
    }

    // Square: the portrait is rendered as a circle.
    $imageAttrs = ['class' => 'img-fluid rounded-circle mb-4 px-4', 'ratio' => '1'];

    if ($imageAlt !== '') {
        $imageAttrs['alt'] = $imageAlt;
    }
    ?>
    <div class="col mb-5 mb-xl-0">
        <div class="text-center">
            <?= render_image($imageValue, $imageAttrs) ?>
            <h5 class="fw-bolder"><?= e($props['name'] ?? '') ?></h5>
            <div class="fst-italic text-muted"><?= e($props['role'] ?? '') ?></div>
        </div>
    </div>
    <?php
},

];
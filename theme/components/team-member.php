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
        'type' => 'text',
        'label' => 'Portrait',
        'required' => true,
        'default' => '150x150.png'
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
    $imageAlt = $props['image_alt'] ?? $props['name'] ?? '';
    ?>
    <div class="col mb-5 mb-xl-0">
        <div class="text-center">
            <img class="img-fluid rounded-circle mb-4 px-4" src="<?= e(img($props['image'] ?? '')) ?>" alt="<?= e($imageAlt) ?>" />
            <h5 class="fw-bolder"><?= e($props['name'] ?? '') ?></h5>
            <div class="fst-italic text-muted"><?= e($props['role'] ?? '') ?></div>
        </div>
    </div>
    <?php
},

];
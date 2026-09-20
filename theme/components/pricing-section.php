<?php
// theme/components/pricing-section.php

return [

'label' => 'Pricing Section',

'schema' => [
    'title' => [
        'type' => 'text',
        'label' => 'Section Title',
        'required' => true,
        'default' => 'Pay as you grow'
    ],
    'subtitle' => [
        'type' => 'text',
        'label' => 'Section Subtitle',
        'required' => false,
        'default' => 'With our no hassle pricing plans'
    ],
],

'children' => 'some',
'allowed_children' => ['pricing-plan'],
'css' => '',

'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'pricing-' . uniqid();
    ?>
    <section id="<?= e($id) ?>" class="bg-light py-5">
        <div class="container px-5 my-5">
            <div class="text-center mb-5">
                <h2 class="h1 fw-bolder"><?= e($props['title'] ?? '') ?></h2>
                <?php if (!empty($props['subtitle'])): ?>
                    <p class="lead fw-normal text-muted mb-0"><?= e($props['subtitle']) ?></p>
                <?php endif; ?>
            </div>
            <div class="row gx-5 justify-content-center">
                <?php if (!empty($props['children'])): ?>
                    <?php render_components($props['children'], $page, $collectedJs, $collectedCss); ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
},

];
<?php
// theme/components/portfolio-cta-section.php

return [

'label' => 'Portfolio Call To Action Section',
'description' => "A closing call to action for the portfolio pages.",
'schema' => [
    'title' => ['type' => 'text', 'label' => 'CTA Title', 'required' => true, 'default' => "Let's build something together"],
    'button_url' => ['type' => 'text', 'label' => 'Button URL', 'required' => true, 'default' => '#'],
    'button_text' => ['type' => 'text', 'label' => 'Button Text', 'required' => true, 'default' => 'Contact us'],
],
'children' => 'none',
'allowed_children' => [],
'css' => '',
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'portfolio-cta-' . uniqid();
    ?>
    <section id="<?= e($id) ?>" class="py-5 bg-light">
        <div class="container px-5 my-5">
            <h2 class="display-4 fw-bolder mb-4"><?= e($props['title'] ?? '') ?></h2>
            <a class="btn btn-lg btn-primary" href="<?= e(url($props['button_url'] ?? '#')) ?>"><?= e($props['button_text'] ?? '') ?></a>
        </div>
    </section>
    <?php
},
];
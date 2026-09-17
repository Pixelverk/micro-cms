<?php
// theme/components/contact-features-section.php

return [

'label' => 'Contact Features Section',

'schema' => [],

'children' => 'some',
'allowed_children' => ['feature-card'],

'css' => '',

'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'contact-features-' . uniqid();
    ?>
    <section id="<?= e($id) ?>" class="py-0">
        <div class="container px-5">
            <div class="row gx-5 row-cols-2 row-cols-lg-4 py-5">
                <?php if (!empty($props['children'])): ?>
                    <?php render_components($props['children'], $page, $collectedJs, $collectedCss); ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
},

];
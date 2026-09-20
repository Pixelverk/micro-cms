<?php
// theme/components/faq-section.php

return [

'label' => 'FAQ Section',

'schema' => [
    'title' => [
        'type' => 'text',
        'label' => 'Section Title',
        'required' => true,
        'default' => 'Frequently Asked Questions'
    ],
    'subtitle' => [
        'type' => 'text',
        'label' => 'Section Subtitle',
        'required' => false,
        'default' => 'How can we help you?'
    ],
    'heading' => [
        'type' => 'text',
        'label' => 'Accordion Heading',
        'required' => false,
        'default' => 'Common Questions'
    ],
    'contact_title' => [
        'type' => 'text',
        'label' => 'Contact Card Title',
        'required' => false,
        'default' => 'Have more questions?'
    ],
    'contact_email' => [
        'type' => 'email',
        'label' => 'Contact Email',
        'required' => false,
        'default' => 'support@example.com'
    ],
],

'children' => 'some',
'allowed_children' => ['faq-item'],
'css' => '',

'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'faq-' . uniqid();
    ?>
    <section id="<?= e($id) ?>" class="py-5">
        <div class="container px-5 my-5">
            <div class="text-center mb-5">
                <h2 class="h1 fw-bolder"><?= e($props['title'] ?? '') ?></h2>
                <?php if (!empty($props['subtitle'])): ?><p class="lead fw-normal text-muted mb-0"><?= e($props['subtitle']) ?></p><?php endif; ?>
            </div>
            <div class="row gx-5">
                <div class="col-xl-8">
                    <?php if (!empty($props['heading'])): ?><h2 class="fw-bolder mb-3"><?= e($props['heading']) ?></h2><?php endif; ?>
                    <div class="accordion mb-5 mb-xl-0" id="<?= e($id) ?>-accordion">
                        <?php if (!empty($props['children'])): ?>
                            <?php render_components($props['children'], $page, $collectedJs, $collectedCss); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card border-0 bg-light mt-xl-5">
                        <div class="card-body p-4 py-lg-5">
                            <div class="text-center">
                                <?php if (!empty($props['contact_title'])): ?><div class="h6 fw-bolder"><?= e($props['contact_title']) ?></div><?php endif; ?>
                                <?php if (!empty($props['contact_email'])): ?>
                                    <p class="text-muted mb-0">Contact us at<br /><a href="mailto:<?= e($props['contact_email']) ?>"><?= e($props['contact_email']) ?></a></p>
                                <?php endif; ?>
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
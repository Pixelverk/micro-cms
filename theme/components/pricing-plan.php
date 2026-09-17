<?php
// theme/components/pricing-plan.php

return [

'label' => 'Pricing Plan',

'schema' => [
    'name' => [
        'type' => 'text',
        'label' => 'Plan Name',
        'required' => true,
        'default' => 'Free'
    ],
    'price' => [
        'type' => 'text',
        'label' => 'Price',
        'required' => true,
        'default' => '$0'
    ],
    'period' => [
        'type' => 'text',
        'label' => 'Billing Period',
        'required' => false,
        'default' => '/ mo.'
    ],
    'features' => [
        'type' => 'textarea',
        'label' => 'Features (one per line; prefix unavailable items with -)',
        'required' => true,
        'default' => "1 users\n5GB storage\nUnlimited public projects\nCommunity access\n-Unlimited private projects\n-Dedicated support"
    ],
    'button_url' => [
        'type' => 'text',
        'label' => 'Button URL',
        'required' => false,
        'default' => '#'
    ],
    'button_text' => [
        'type' => 'text',
        'label' => 'Button Text',
        'required' => false,
        'default' => 'Choose plan'
    ],
    'featured' => [
        'type' => 'select',
        'label' => 'Featured Plan',
        'required' => true,
        'options' => [
            'no' => 'No',
            'yes' => 'Yes',
        ],
        'default' => 'no'
    ],
],

'children' => 'none',
'allowed_children' => [],
'css' => '',

'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $features = preg_split('/\r\n|\r|\n/', (string) ($props['features'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $featured = ($props['featured'] ?? 'no') === 'yes';
    ?>
    <div class="col-lg-6 col-xl-4">
        <div class="card mb-5 mb-xl-0">
            <div class="card-body p-5">
                <div class="small text-uppercase fw-bold <?= $featured ? '' : 'text-muted' ?>">
                    <?php if ($featured): ?><i class="bi bi-star-fill text-warning"></i><?php endif; ?>
                    <?= e($props['name'] ?? '') ?>
                </div>
                <div class="mb-3">
                    <span class="display-4 fw-bold"><?= e($props['price'] ?? '') ?></span>
                    <?php if (!empty($props['period'])): ?><span class="text-muted"><?= e($props['period']) ?></span><?php endif; ?>
                </div>
                <ul class="list-unstyled mb-4">
                    <?php foreach ($features as $feature): ?>
                        <?php $available = !str_starts_with($feature, '-'); $label = ltrim($feature, '-'); ?>
                        <li class="mb-2 <?= $available ? '' : 'text-muted' ?>">
                            <i class="bi <?= $available ? 'bi-check text-primary' : 'bi-x' ?>"></i>
                            <?= e($label) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!empty($props['button_text'])): ?>
                    <div class="d-grid"><a class="btn <?= $featured ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url($props['button_url'] ?? '#')) ?>"><?= e($props['button_text']) ?></a></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
},

];
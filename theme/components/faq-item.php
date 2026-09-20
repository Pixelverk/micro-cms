<?php
// theme/components/faq-item.php

return [

'label' => 'FAQ Item',
'description' => "One question and answer, as an accordion row.",

'schema' => [
    'question' => [
        'type' => 'text',
        'label' => 'Question',
        'required' => true,
        'default' => 'How does this work?'
    ],
    'answer' => [
        'type' => 'textarea',
        'label' => 'Answer',
        'required' => true,
        'default' => 'Add a clear answer to this frequently asked question.'
    ],
    'open' => [
        'type' => 'select',
        'label' => 'Open by Default',
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
    $id = 'faq-item-' . uniqid();
    $open = ($props['open'] ?? 'no') === 'yes';
    ?>
    <div class="accordion-item">
        <h3 class="accordion-header" id="<?= e($id) ?>-heading">
            <button class="accordion-button <?= $open ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= e($id) ?>-collapse" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="<?= e($id) ?>-collapse">
                <?= e($props['question'] ?? '') ?>
            </button>
        </h3>
        <div class="accordion-collapse collapse <?= $open ? 'show' : '' ?>" id="<?= e($id) ?>-collapse" aria-labelledby="<?= e($id) ?>-heading">
            <div class="accordion-body"><?= nl2br(e($props['answer'] ?? '')) ?></div>
        </div>
    </div>
    <?php
},

];
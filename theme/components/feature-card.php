<?php
// theme/components/feature-card.php

return [

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'title' => [
        'type' => 'string',
        'label' => 'Feature Title',
        'required' => true,
        'default' => 'Default Title'
    ],
    'text' => [
        'type' => 'string',
        'label' => 'Feature Text',
        'required' => true,
        'default' => 'Default Text'
    ],
    'icon' => [
        'type' => 'string',
        'label' => 'Feature Icon',
        'required' => false,
        'default' => '👋'
    ],
    'image' => [
        'type' => 'string',
        'label' => 'Feature Image',
        'required' => false,
        'default' => 'placeholder.png'
    ]
],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'none', // 'any', 'none', or 'some'
'allowed_children' => [], // only used if children='some'

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => <<<CSS
.card {
    display:block;
    background: #fff;
    border-radius: 8px;
    padding: 2rem 1rem;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    text-align: center;
}

.card img {
    max-width: 100px;
    height: auto;
    margin-bottom: 1rem;
}

.card h2 {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.card p {
    font-size: 1rem;
    color: #555;
}

.card .icon {
    font-size: 2rem;
    margin-bottom: 1rem;
}
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'feature-' . uniqid();

    extract($props, EXTR_SKIP);

    ?>
    <div class="card">
        <img src="<?= img($image) ?>" alt="<?= e($title) ?>">
        <div class="icon"><?= e($icon ?? '#')?></div>
        <h2><?= e($title)?></h2>
        <p><?= e($text)?></p>
    </div>
    <?php
},

];
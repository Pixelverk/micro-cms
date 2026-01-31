<?php
// theme/components/hero-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Hero Section',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'title' => [
        'type' => 'text',
        'label' => 'Hero Title',
        'required' => true,
        'default' => 'Default Title'
    ],
    'subtitle' => [
        'type' => 'text',
        'label' => 'Hero subtitle',
        'required' => true,
        'default' => 'Default Text'
    ],
    'image' => [
        'type' => 'text',
        'label' => 'Hero Image',
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
.hero {
    text-align: center;
    padding: 4rem 2rem;
    background: #333;
    color: #fff;
}

.hero img {
    max-width: 100%;
    height: auto;
    margin-bottom: 2rem;
    display: block;
    margin-left: auto;
    margin-right: auto;
}

.hero h1 {
    font-size: 2.5rem;
    margin-bottom: 1rem;
}

.hero p {
    font-size: 1.25rem;
    color: #ddd;
}

.hero .inner {
    gap: 4rem;
}
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'hero-' . uniqid();

    extract($props, EXTR_SKIP);

    ?>
    <section id="<?= $id ?>" class="hero">
        <div class="inner">
            <div class="hero-text">
                <h1><?= e($title)?></h1>
                <p><?= e($subtitle)?></p>

                <div class="meta"> <!-- just testing -->

                    <?php if ($page['categories'] ?? false): ?>
                        <div class="cats">
                            <?php foreach ($page['categories'] as $cat): ?>
                                <a href="/category/<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($page['tags'] ?? false): ?>
                        <div class="tags">
                            <?php foreach ($page['tags'] as $tag): ?>
                                <a href="/tag/<?= e($tag['slug']) ?>">#<?= e($tag['name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>

            </div>
            <div class="hero-img">
                <img src="<?= img($image) ?>" alt="<?= e($title) ?>">
            </div>
        </div>
    </section>
    <?php
},

];
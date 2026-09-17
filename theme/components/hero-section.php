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
        'default' => '600x400.png'
    ],
    'btn1_url' => [
        'type' => 'text',
        'label' => 'Btn 1 url',
        'required' => false,
        'default' => '#'
    ],
    'btn1_text' => [
        'type' => 'text',
        'label' => 'Btn 1 text',
        'required' => false,
        'default' => 'Get Started'
    ],
    'btn2_url' => [
        'type' => 'text',
        'label' => 'Btn 2 url',
        'required' => false,
        'default' => '#'
    ],
    'btn2_text' => [
        'type' => 'text',
        'label' => 'Btn 2 text',
        'required' => false,
        'default' => 'Learn More'
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
/*
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
*/
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'hero-' . uniqid();

    extract($props, EXTR_SKIP);

    // Components may be added without every optional prop, so read them
    // defensively instead of relying on extract() to have created them.
    $title     = $title ?? '';
    $subtitle  = $subtitle ?? '';
    $image     = $image ?? '';
    $btn1Text  = $btn1_text ?? '';
    $btn2Text  = $btn2_text ?? '';

    ?>
    <section id="<?= $id ?>" class="bg-dark py-5">
        <div class="container px-5">
            <div class="row gx-5 align-items-center justify-content-center">
                <div class="col-lg-8 col-xl-7 col-xxl-6">
                    <div class="my-5 text-center text-xl-start">
                        <h1 class="display-5 fw-bolder text-white mb-2"><?= e($title) ?></h1>
                        <p class="lead fw-normal text-white-50 mb-4"><?= e($subtitle) ?></p>
                        <?php if ($btn1Text !== '' || $btn2Text !== ''): ?>
                            <div class="d-grid gap-3 d-sm-flex justify-content-sm-center justify-content-xl-start">
                                <?php if ($btn1Text !== ''): ?>
                                    <a class="btn btn-primary btn-lg px-4 me-sm-3" href="<?= e($btn1_url ?? '#') ?>"><?= e($btn1Text) ?></a>
                                <?php endif; ?>
                                <?php if ($btn2Text !== ''): ?>
                                    <a class="btn btn-outline-light btn-lg px-4" href="<?= e($btn2_url ?? '#') ?>"><?= e($btn2Text) ?></a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($image !== ''): ?>
                    <div class="col-xl-5 col-xxl-6 d-none d-xl-block text-center"><img class="img-fluid rounded-3 my-5" src="<?= img($image) ?>" alt="<?= e($title) ?>" /></div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php
},

];
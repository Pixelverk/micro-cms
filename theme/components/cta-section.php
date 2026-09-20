<?php
// theme/components/cta-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'CTA Section',
'description' => "A full-width call to action on a dark band.",

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'title' => [
        'type' => 'string',
        'label' => 'CTA Title',
        'required' => true,
        'default' => 'Default Title'
    ],
    'text' => [
        'type' => 'string',
        'label' => 'CTA Text',
        'required' => true,
        'default' => 'Default Text'
    ],
    'url' => [
        'type' => 'string',
        'label' => 'CTA URL',
        'required' => true,
        'default' => '#'
    ],
    'linktext' => [
        'type' => 'string',
        'label' => 'CTA Link Text',
        'required' => true,
        'default' => 'Click Me'
    ]
],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'any', // 'any', 'none', or 'some'
'allowed_children' => [], // only used if children='some'

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => <<<CSS
.cta {
    padding: 3rem 2rem;
    text-align: center;
    background: #e0f7fa;
}

.cta .inner {
    display: block;
}

.cta h2 {
    font-size: 2rem;
    margin-bottom: 1rem;
}

.cta p {
    font-size: 1.2rem;
    margin-bottom: 1.5rem;
}

.cta-button {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    background: #00796b;
    color: #fff;
    text-decoration: none;
    border-radius: 4px;
}
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'cta-' . uniqid();

    extract($props, EXTR_SKIP);

    // Optional props: a block or an older instance may omit them, and passing
    // null to url() would be a fatal error rather than a missing link.
    $title    = $title ?? '';
    $text     = $text ?? '';
    $linktext = $linktext ?? '';
    $url      = $url ?? '';

    ?>
    <section id="<?= $id ?>" class="cta">
        <div class="inner">
            <h2><?= e($title) ?></h2>
            <p><?= e($text) ?></p>
            <?php if ($linktext !== ''): ?>
                <a href="<?= e($url !== '' ? url($url) : '#') ?>" class="cta-button">
                    <?= e($linktext) ?>
                </a>
            <?php endif; ?>
            <?php if (!empty($children)) {render_components($children, $page, $collectedJs, $collectedCss);} ?>
        </div>
    </section>
    <?php
},

];
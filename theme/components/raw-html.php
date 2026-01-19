<?php
// theme/components/raw-html.php
// treat raw html like a type of input field, it will need a template in content-editor-templates.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Raw HTML',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'content' => [
        'type' => 'html',
        'label' => 'RAW HTML',
        'default' => 'Write any raw html here'
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

CSS,

/** --------------------------------------------
 * Component JS (optional)
 * -------------------------------------------- */
'js' => <<<JS

JS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'cta-' . uniqid();

    extract($props, EXTR_SKIP);

    ?>
    <section id="<?= $id ?>" class="raw-html-output">
        <div class="inner">
            <?= $content?>
        </div>
    </section>
    <?php
},

];
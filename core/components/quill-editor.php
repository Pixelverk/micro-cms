<?php
// theme/components/quill-editor.php
// treat quill editor like a type of input field, it will need a template in content-editor-templates.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Rich Text',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'content' => [
        'type' => 'quill',
        'label' => 'Rich Text',
        'default' => 'Write something nice here'
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
    <section id="<?= $id ?>" class="quill-editor-output">
        <div class="inner">
            <?= $content?>
        </div>
    </section>
    <?php
},

];
<?php
// core/components/quill-editor.php

// Rich text arrives as HTML and is rendered as-is. The editor template for the
// 'quill' field type lives in admin/partials/content-editor-templates.php.

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Rich Text',
'description' => "A rich-text block: headings, paragraphs, lists and links.",

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
'css' => '',

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'quill-' . uniqid();

    extract($props, EXTR_SKIP);

    ?>
    <div id="<?= $id ?>" class="quill-editor-output">
        <?= $content ?>
    </div>
    <?php
},

];

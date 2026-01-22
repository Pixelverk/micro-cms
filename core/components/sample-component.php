<?php
// theme/components/component-name.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Sample Component',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'title' => [
        'type' => 'text', // type of input field, text, textarea, checkbox, color, etc. Available ones listed in content-editor-templates.php
        'label' => 'Title',
        'default' => 'Default Title',
    ],
    // Add more fields here
],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'some', // 'any', 'none', or 'some'
'allowed_children' => ['child-component-name'], // only used if children='some'

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => <<<CSS
/* Component CSS here */
.component-name {
    padding: 2rem;
}
CSS,

/** --------------------------------------------
 * Component JS (optional)
 * -------------------------------------------- */
'js' => <<<JS
// Component JS here
console.log('Component loaded: component-name');
JS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'component-' . uniqid();
    extract($props, EXTR_SKIP);
    ?>
    <div id="<?= $id ?>" class="component-name">
        <!-- Render dynamic content or children -->
        <?php if (!empty($children)) { render_components($children, $collectedJs, $collectedCss); } ?>
    </div>
    <?php
},

];
<?php
// theme/components/policy-section.php

return [

'label' => 'Policy Content',

'schema' => [],

'children' => 'some',
'allowed_children' => ['quill-editor'],

'css' => <<<CSS
.policy-content {
    color: #343a40;
    font-size: 1.05rem;
    line-height: 1.75;
}

.policy-content h2,
.policy-content h3 {
    margin-top: 2.5rem;
    margin-bottom: 1rem;
}

.policy-content p,
.policy-content ul,
.policy-content ol {
    margin-bottom: 1.25rem;
}
CSS,

'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    ?>
    <div class="policy-content">
        <?php if (!empty($props['children'])): ?>
            <?php render_components($props['children'], $page, $collectedJs, $collectedCss); ?>
        <?php endif; ?>
    </div>
    <?php
},

];
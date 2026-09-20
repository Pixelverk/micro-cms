<?php
// core/components/404.php
//
// Fallback for a not-found page. The router synthesises a page whose only
// component is this one when no 404 content exists yet, so it must render
// without depending on a theme file. A theme with its own 404 page never
// reaches it.

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => '404 Not Found',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'none',
'allowed_children' => [],

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => <<<CSS
.cms-not-found {
    max-width: 40rem;
    margin: 4rem auto;
    padding: 0 1.5rem;
    text-align: center;
}
.cms-not-found h2 {
    margin: 0 0 0.5rem;
}
.cms-not-found p {
    margin: 0 0 1.5rem;
}
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    ?>
    <section class="cms-not-found">
        <h2 class="h1"><?= e('Page not found') ?></h2>
        <p><?= e('The page you are looking for does not exist or has been moved.') ?></p>
        <p><a class="btn btn-primary" href="<?= e(url('')) ?>"><?= e('Go to the home page') ?></a></p>
    </section>
    <?php
},

];

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Default Layout
|--------------------------------------------------------------------------
|
| Variables available:
| - $page (array)
| - &$collectedJs
| - &$collectedCss
|
*/

?>
<header>
    <?php render_components($page['layout']['header'] ?? [], $collectedJs, $collectedCss); ?>
</header>

<main>
    <?php render_components($page['components'] ?? [], $collectedJs, $collectedCss); ?>
</main>

<footer>
    <?php render_components($page['layout']['footer'] ?? [], $collectedJs, $collectedCss); ?>
</footer>
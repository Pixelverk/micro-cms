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

<main>
    <?php render_components($page['components'] ?? [], $collectedJs, $collectedCss); ?>
</main>
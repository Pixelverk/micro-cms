<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Landing Layout, a.k.a no header or footer
|--------------------------------------------------------------------------
|
| Variables available:
| - $page (array)
| - &$collectedJs
| - &$collectedCss
|
*/

echo('<main>');
render_components($page['components'], $collectedJs, $collectedCss);
echo('</main>');
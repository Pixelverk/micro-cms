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

// The id is the skip link's target; the page's own title is the one <h1>.
echo('<main id="main-content">');

if (($page['title'] ?? '') !== '') {
    echo('<h1 class="visually-hidden">' . e((string) $page['title']) . '</h1>');
}

render_components($page['components'], $page, $collectedJs, $collectedCss);
echo('</main>');
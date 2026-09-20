<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Default Layout
|--------------------------------------------------------------------------
|
| Variables available:
| - $page (array)
| - $headerComponent
| - $footerComponent
| - &$collectedJs
| - &$collectedCss
|
*/

// Render header
component($headerComponent, [], $page, $collectedJs, $collectedCss);

// Render page components. The id is the skip link's target, and the page's own
// title is the one <h1>: the section components below it all render <h2>.
echo('<main id="main-content">');

if (($page['title'] ?? '') !== '') {
    echo('<h1 class="visually-hidden">' . e((string) $page['title']) . '</h1>');
}

if (!empty($page['components'])) {
    render_components($page['components'], $page, $collectedJs, $collectedCss);
}

echo('</main>');

// Render footer
component($footerComponent, [], $page, $collectedJs, $collectedCss);
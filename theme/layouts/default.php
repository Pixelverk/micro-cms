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
component($headerComponent, [], $collectedJs, $collectedCss);

// Render page components
if (!empty($page['components'])) {
    echo('<main>');
    render_components($page['components'], $collectedJs, $collectedCss);
    echo('</main>');
}

// Render footer
component($footerComponent, [], $collectedJs, $collectedCss);

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taxonomy Archive Layout (Enhanced)
|--------------------------------------------------------------------------
|
| Variables available:
| - $page (array) → includes 'taxonomy' and 'items'
| - $headerComponent
| - $footerComponent
| - &$collectedJs
| - &$collectedCss
|
*/
// Archive styling (injected as inline CSS, only on pages that need it)
require theme('partials/taxonomy-archive.css.php');

// Render header
component($headerComponent, [], $page, $collectedJs, $collectedCss);

// Main content
echo '<main class="taxonomy-archive-page">';
echo '<div class="inner flex-column">';

$taxonomy = $page['taxonomy'] ?? null;
$items    = $page['items'] ?? [];

if (!$taxonomy) {
    echo '<p>Taxonomy not found.</p>';
} else {
    echo '<header>';
    echo '<h1>Blog! ' . htmlspecialchars($taxonomy['name'], ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>Type: ' . htmlspecialchars($taxonomy['taxonomy_type'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</header>';

    if (empty($items)) {
        echo '<p>No items found in this ' . htmlspecialchars($taxonomy['taxonomy_type']) . '.</p>';
    } else {
        echo '<ul class="taxonomy-items">';
        foreach ($items as $item) {
            $title = htmlspecialchars($item['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8');
            $url   = htmlspecialchars(content_url($item), ENT_QUOTES, 'UTF-8');

            echo '<li class="taxonomy-item">';

            // Optional thumbnail
            if (!empty($item['meta']['thumbnail'])) {
                echo "<a href=\"{$url}\">" . render_image($item['meta']['thumbnail'], ['class' => 'taxonomy-item-thumb', 'alt' => $item['title'] ?? 'Untitled']) . '</a>';
            }

            // Title
            echo "<h2><a href=\"{$url}\">{$title}</a></h2>";

            // Published date
            if (!empty($item['published_at'])) {
                $date = format_date((int) $item['published_at']);
                echo "<p class=\"taxonomy-item-date\">Published: {$date}</p>";
            }

            // Optional excerpt
            if (!empty($item['meta']['excerpt'])) {
                $excerpt = htmlspecialchars($item['meta']['excerpt'], ENT_QUOTES, 'UTF-8');
                echo "<p class=\"taxonomy-item-excerpt\">{$excerpt}</p>";
            }

            // Categories & Tags
            $categories = array_column($item['categories'] ?? [], 'name');
            $tags       = array_column($item['tags'] ?? [], 'name');

            if ($categories) {
                echo '<span class="taxonomy-categories">Category: ' . implode(', ', array_map('htmlspecialchars', $categories)) . '</span>';
            }
            if ($tags) {
                echo '<span class="taxonomy-tags">Tags: ' . implode(', ', array_map('htmlspecialchars', $tags)) . '</span>';
            }

            echo '</li>';
        }
        echo '</ul>';

        // Prev/next for this archive (only renders when there is more than one page).
        // The partial reads $pagination; the layout holds it under $page.
        $pagination = $page['pagination'] ?? null;
        $pagerUrl   = url($page['path'] ?? '');
        $pagerLabel = 'Blog archive pages';
        require theme('partials/pager.php');
    }
}
echo '</div>';
echo '</main>';

// Render footer
component($footerComponent, [], $page, $collectedJs, $collectedCss);
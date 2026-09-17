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
$theme    = theme_config();
$settings = load_settings();

// Render header
component($headerComponent, [], $page, $collectedJs, $collectedCss);

// Main content
echo '<main class="taxonomy-archive-page">';
echo '<div class="inner flex-col">';

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
            $slug  = htmlspecialchars($item['slug'] ?? '', ENT_QUOTES, 'UTF-8');

            $type = $item['type'] ?? 'page';
            $ctConfig = $theme['content_types'][$type] ?? [];
            $prefix   = $settings['content_prefixes'][$type] ?? $ctConfig['url_prefix'] ?? '';
            $url      = '/' . ($prefix ? $prefix . '/' : '') . $slug;

            echo '<li class="taxonomy-item">';

            // Optional thumbnail
            if (!empty($item['meta']['thumbnail'])) {
                $thumb = htmlspecialchars($item['meta']['thumbnail'], ENT_QUOTES, 'UTF-8');
                echo "<a href=\"{$url}\"><img class=\"taxonomy-item-thumb\" src=\"{$thumb}\" alt=\"{$title}\"></a>";
            }

            // Title
            echo "<h2><a href=\"{$url}\">{$title}</a></h2>";

            // Published date
            if (!empty($item['published_at'])) {
                $date = date('F j, Y', (int)$item['published_at']);
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
    }
}
echo '</div>';
echo '</main>';

// Render footer
component($footerComponent, [], $page, $collectedJs, $collectedCss);
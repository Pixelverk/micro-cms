<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taxonomy archive CSS
|--------------------------------------------------------------------------
| Shared styling for archive layouts (theme/layouts/taxonomy.php and
| blog-archive.php). It lives here rather than in style.css because it is
| specific to those layouts, and it is injected through collect_css() so it
| only ships on pages that actually use it.
|
| Expects &$collectedCss to be in scope.
|--------------------------------------------------------------------------
*/

collect_css($collectedCss, 'layout:taxonomy-archive', <<<'CSS'
/* TAXONOMY ARCHIVE PAGES */

.taxonomy-archive-page {
    padding: 0 1rem;
    font-family: sans-serif;
}

.taxonomy-archive-page .inner {
    display: flex;
    flex-direction: column;
    gap: 2rem;
}

/* Header */
.taxonomy-archive-page header {
    text-align: center;
    margin-bottom: 2rem;
}

.taxonomy-archive-page header h1 {
    font-size: 2rem;
    margin: 0.5rem 0;
}

.taxonomy-archive-page header p {
    color: #555;
    font-size: 0.9rem;
}

/* Items list */
.taxonomy-archive-page .taxonomy-items {
    display: grid;
    /* auto-fill keeps the empty tracks, so a lone entry is one card wide
       instead of the width of the whole row. */
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 2rem;
    list-style: none;
    padding: 0;
    margin: 0;
    width:100%;
}

/* Individual item */
.taxonomy-archive-page .taxonomy-item {
    background: #fff;
    border-radius: 0.5rem;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    padding: 1rem;
    display: flex;
    flex-direction: column;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.taxonomy-archive-page .taxonomy-item:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

/* Title link */
.taxonomy-archive-page .taxonomy-item a {
    font-weight: bold;
    font-size: 1.25rem;
    margin-bottom: 0.5rem;
    color: #222;
    text-decoration: none;
}

.taxonomy-archive-page .taxonomy-item a:hover {
    text-decoration: underline;
}

/* Categories & Tags */
.taxonomy-archive-page .taxonomy-item .taxonomy-categories,
.taxonomy-archive-page .taxonomy-item .taxonomy-tags {
    font-size: 0.85rem;
    color: #555;
    margin-top: 0.25rem;
}

/* Responsive tweak */
@media (max-width: 600px) {
    .taxonomy-archive-page .taxonomy-items {
        grid-template-columns: 1fr;
    }
}

/* Thumbnail */
.taxonomy-archive-page .taxonomy-item-thumb {
    width: 100%;
    height: auto;
    border-radius: 0.5rem;
    margin-bottom: 0.5rem;
}

/* Title */
.taxonomy-archive-page .taxonomy-item h2 {
    margin: 0.25rem 0;
    font-size: 1.5rem;
}

.taxonomy-archive-page .taxonomy-item h2 a {
    color: #222;
    text-decoration: none;
}

.taxonomy-archive-page .taxonomy-item h2 a:hover {
    text-decoration: underline;
}

/* Published date */
.taxonomy-archive-page .taxonomy-item-date {
    font-size: 0.85rem;
    color: #888;
    margin-bottom: 0.5rem;
}

/* Excerpt */
.taxonomy-archive-page .taxonomy-item-excerpt {
    font-size: 1rem;
    color: #444;
    margin-bottom: 0.5rem;
}

/* Categories & Tags remain as before */
.taxonomy-archive-page .taxonomy-item .taxonomy-categories,
.taxonomy-archive-page .taxonomy-item .taxonomy-tags {
    font-size: 0.85rem;
    color: #555;
    margin-top: 0.25rem;
    display: inline-block;
}
/* Search results */
.search-inner { gap: 1.5rem; }

.search-header h1 { margin-bottom: 0.25rem; }

.search-form {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
}

.search-form input[type="search"] {
    flex: 1 1 16rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--theme-border);
    border-radius: var(--theme-radius);
    font: inherit;
}

.search-results {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    width: 100%;
}

.search-result h2 { margin: 0 0 0.25rem; font-size: 1.25rem; }
.search-result h2 a { color: var(--theme-dark); text-decoration: none; }
.search-result h2 a:hover { text-decoration: underline; }

.search-excerpt { margin: 0 0 0.35rem; color: #444; }

.search-meta { font-size: 0.85rem; }

.search-empty { color: #555; padding: 1rem 0; }

.search-pagination {
    display: flex;
    align-items: center;
    gap: 1rem;
}

mark {
    background: #fef08a;
    color: inherit;
    padding: 0 0.1em;
}

CSS);

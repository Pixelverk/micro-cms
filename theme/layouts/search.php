<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Search Results Layout
|--------------------------------------------------------------------------
|
| Variables available:
| - $page (array) → includes 'query', 'filters' and 'results'
| - $headerComponent / $footerComponent
| - &$collectedJs / &$collectedCss
|
*/

// List-page styling (shared with the taxonomy archives)
require theme('partials/taxonomy-archive.css.php');

$results = $page['results'] ?? ['items' => [], 'total' => 0, 'query' => '', 'page' => 1, 'pages' => 1];
$query   = (string) ($results['query'] ?? '');
$filters = $page['filters'] ?? [];
$theme   = theme_config();

// Render header
component($headerComponent, [], $page, $collectedJs, $collectedCss);

$action = url('search');

// Preserve filters in pagination links.
$pageUrl = function (int $pageNumber) use ($action, $query, $filters): string {
    $params = array_filter([
        'q'    => $query,
        'type' => $filters['type'] ?? '',
        'page' => $pageNumber > 1 ? $pageNumber : '',
    ], static fn($value) => $value !== '' && $value !== null);

    if (!empty($filters['taxonomy']) && is_array($filters['taxonomy'])) {
        $params['taxonomy'] = $filters['taxonomy'];
    }

    return $action . ($params ? '?' . http_build_query($params) : '');
};
?>
<main id="main-content" class="search-page">
    <div class="inner flex-column search-inner">

        <header class="search-header">
            <h1><?= $query !== '' ? 'Search results' : 'Search' ?></h1>

            <?php if ($query !== ''): ?>
                <p class="text-muted">
                    <?= (int) $results['total'] ?> result<?= (int) $results['total'] === 1 ? '' : 's' ?>
                    for &ldquo;<?= e($query) ?>&rdquo;
                </p>
            <?php endif; ?>

            <form class="search-form" method="get" action="<?= e($action) ?>" role="search">
                <input type="search" name="q" value="<?= e($query) ?>"
                       placeholder="Search this site…" aria-label="Search this site" required>

                <?php if (!empty($filters['type'])): ?>
                    <input type="hidden" name="type" value="<?= e($filters['type']) ?>">
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">Search</button>
            </form>
        </header>

        <?php if ($query !== '' && !search_query_is_valid($query)): ?>
            <p class="search-empty">
                Please enter at least <?= (int) search_min_length() ?> characters.
            </p>
        <?php elseif ($query === ''): ?>
            <p class="search-empty">Type a word or phrase to search the site.</p>
        <?php elseif (empty($results['items'])): ?>
            <p class="search-empty">
                Nothing matched &ldquo;<?= e($query) ?>&rdquo;. Try a different word.
            </p>
        <?php else: ?>
            <ul class="search-results">
                <?php foreach ($results['items'] as $item): ?>
                    <li class="search-result">
                        <h2>
                            <a href="<?= e($item['url']) ?>">
                                <?= search_highlight(e($item['title']), $query) ?>
                            </a>
                        </h2>

                        <?php if (!empty($item['excerpt'])): ?>
                            <p class="search-excerpt">
                                <?= search_highlight(e($item['excerpt']), $query) ?>
                            </p>
                        <?php endif; ?>

                        <p class="search-meta text-muted">
                            <span class="search-type"><?= e($theme['content_types'][$item['type']]['label'] ?? ucfirst($item['type'])) ?></span>
                            <?php if (!empty($item['published_at'])): ?>
                                &middot; <?= e(format_date((int) $item['published_at'], 'j M Y')) ?>
                            <?php endif; ?>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ((int) $results['pages'] > 1): ?>
                <nav class="search-pagination" aria-label="Search result pages">
                    <?php if ((int) $results['page'] > 1): ?>
                        <a class="btn btn-outline-primary" href="<?= e($pageUrl((int) $results['page'] - 1)) ?>">&larr; Previous</a>
                    <?php endif; ?>

                    <span class="text-muted">
                        Page <?= (int) $results['page'] ?> of <?= (int) $results['pages'] ?>
                    </span>

                    <?php if ((int) $results['page'] < (int) $results['pages']): ?>
                        <a class="btn btn-outline-primary" href="<?= e($pageUrl((int) $results['page'] + 1)) ?>">Next &rarr;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</main>
<?php
component($footerComponent, [], $page, $collectedJs, $collectedCss);

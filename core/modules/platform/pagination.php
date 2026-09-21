<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
|
| One place owns how a page number is read and how a result set is sliced, so
| listings stay consistent and the cache rules have a single predicate to
| consult. Deliberately small: no page-size query parameter, and no link
| rendering (that stays with the layout or component doing the listing).
|
| The shape returned by pagination_result() matches what search_content()
| already produces, so a listing can reuse the existing pager markup.
|
*/

/**
 * The requested page, from `?page=`.
 *
 * Always at least 1. An unparsable or negative value is treated as page 1
 * rather than an error: a bad URL should render the listing, not a 404.
 */
function pagination_current_page(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

/**
 * Rows to skip for a page.
 */
function pagination_offset(int $page, int $perPage): int
{
    return (max(1, $page) - 1) * max(1, $perPage);
}

/**
 * Wrap a slice of rows with the totals a pager needs.
 *
 * Also records the listing so render_page() can add rel=prev/next to the head:
 * a component cannot pass values back up (the page array reaches it by value),
 * and re-running the query only to build two link tags would be worse.
 *
 * @param list<array<string, mixed>> $items
 * @param string $baseUrl Url of the listing without a page parameter.
 * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
 */
function pagination_result(array $items, int $total, int $page, int $perPage, string $baseUrl = ''): array
{
    $perPage = max(1, $perPage);
    $page    = max(1, $page);
    $total   = max(0, $total);

    $result = [
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'pages'    => max(1, (int) ceil($total / $perPage)),
        'per_page' => $perPage,
    ];

    if ($baseUrl !== '') {
        $result['url'] = $baseUrl;
    }

    $GLOBALS['cms_pagination'] = $result;

    return $result;
}

/**
 * The URL for one page of a listing.
 *
 * The page number is omitted on page 1, so the first page keeps its clean URL
 * (and its cache entry). The base URL's trailing slash is dropped before the
 * query string is appended, matching how `url()` composes ordinary URLs.
 *
 * @param array<string, scalar|null> $query Extra query parameters to preserve.
 */
function pagination_url(string $baseUrl, int $pageNumber, array $query = []): string
{
    $params = array_filter(
        $query + ['page' => $pageNumber > 1 ? $pageNumber : ''],
        static fn($value) => $value !== '' && $value !== null
    );

    $baseUrl = rtrim($baseUrl, '/');

    return $params ? $baseUrl . '?' . http_build_query($params) : $baseUrl . '/';
}

/**
 * Was this request for a page other than the first?
 *
 * A paged listing depends on the query string, which is not part of the cache
 * key, so such a request must never be served from, or written to, the page
 * cache. This mirrors how search requests are excluded.
 */
function pagination_is_paged_request(): bool
{
    return isset($_GET['page']) && (string) $_GET['page'] !== '' && pagination_current_page() > 1;
}

/**
 * `<link rel="prev">` / `<link rel="next">` for the current listing.
 *
 * Returns '' when the page is not paginated, so it is safe to append to the
 * head unconditionally.
 */
function pagination_link_tags(array $pagination, string $baseUrl): string
{
    $pages   = (int) ($pagination['pages'] ?? 1);
    $current = (int) ($pagination['page'] ?? 1);

    if ($pages <= 1) {
        return '';
    }

    $head = '';

    if ($current > 1) {
        $head .= "<link rel='prev' href='" . e(pagination_url($baseUrl, $current - 1)) . "'>\n";
    }

    if ($current < $pages) {
        $head .= "<link rel='next' href='" . e(pagination_url($baseUrl, $current + 1)) . "'>\n";
    }

    return $head;
}

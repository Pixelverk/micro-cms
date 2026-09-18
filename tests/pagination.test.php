<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
|
| The slicing rules and the listing query. The end-to-end behaviour (pager
| markup, rel=prev/next, cache exclusion) lives in tests/http.test.php-style
| suites; this file pins the arithmetic and the query, where the subtle
| failures are: off-by-one offsets, a missing total, an unstable ORDER BY
| that duplicates a row across pages, and drafts leaking into a listing.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

/**
 * Request a given query string for the rest of the test.
 */
function pagination_get(array $query): void
{
    $_GET = $query;
}

t('pagination_current_page() reads ?page and clamps to 1', function () {
    foreach ([
        [[], 1],
        [['page' => '3'], 3],
        [['page' => 0], 1],
        [['page' => -5], 1],
        [['page' => ''], 1],
        [['page' => 'abc'], 1],
        [['page' => '2.9'], 2],
    ] as [$query, $expected]) {
        pagination_get($query);
        assert_eq($expected, pagination_current_page(), 'query: ' . json_encode($query));
    }

    pagination_get([]);
});

t('pagination_offset() counts whole pages', function () {
    assert_eq(0, pagination_offset(1, 10));
    assert_eq(10, pagination_offset(2, 10));
    assert_eq(20, pagination_offset(3, 10));
    assert_eq(0, pagination_offset(1, 1));

    // A nonsense page or size must never produce a negative offset.
    assert_eq(0, pagination_offset(0, 10));
    assert_eq(0, pagination_offset(1, 0));
});

t('pagination_result() computes the page count', function () {
    assert_eq(1, pagination_result([], 0, 1, 10)['pages'], 'an empty listing is one page');
    assert_eq(1, pagination_result([], 10, 1, 10)['pages'], 'an exact fit is one page');
    assert_eq(2, pagination_result([], 11, 1, 10)['pages'], 'one over starts a second page');
    assert_eq(3, pagination_result([], 25, 1, 10)['pages']);

    $result = pagination_result([['id' => 1]], 25, 2, 10);
    assert_eq(2, $result['page']);
    assert_eq(25, $result['total']);
    assert_eq(10, $result['per_page']);
    assert_count(1, $result['items']);

    // Out-of-range and zero inputs are normalised, never returned raw.
    assert_eq(1, pagination_result([], 0, 0, 0)['page']);
    assert_eq(1, pagination_result([], 0, 0, 0)['per_page']);
});

t('pagination_url() drops ?page on page 1 and trims the trailing slash', function () {
    assert_eq('/blog/', pagination_url('/blog/', 1));
    assert_eq('/blog?page=2', pagination_url('/blog/', 2));
    assert_eq('/category/news?page=3', pagination_url('/category/news/', 3));

    // Extra query parameters are preserved alongside the page number.
    assert_eq('/search?q=cms&page=2', pagination_url('/search/', 2, ['q' => 'cms']));
    assert_eq('/search?q=cms', pagination_url('/search/', 1, ['q' => 'cms']));
    assert_eq('/search?q=cms', pagination_url('/search/', 1, ['q' => 'cms', 'tag' => '']));
});

t('list_content_page() slices one page and reports the total', function () {
    // A private type would be safer, but list_content_page() only serves types
    // the theme declares, so use a declared one and count from a clean slate.
    db()->exec("DELETE FROM content WHERE type = 'blog_post'");

    $now = time();
    foreach (range(1, 7) as $i) {
        seed_content([
            'type'         => 'blog_post',
            'slug'         => 'page-fixture-' . $i,
            'title'        => 'Fixture ' . $i,
            'status'       => 'published',
            // Distinct publish times make the order deterministic.
            'published_at' => $now - $i,
        ]);
    }

    $first = list_content_page('blog_post', 1, 3);
    assert_eq(7, $first['total']);
    assert_eq(3, $first['pages']);
    assert_count(3, $first['items']);

    $second = list_content_page('blog_post', 2, 3);
    assert_count(3, $second['items']);

    $third = list_content_page('blog_post', 3, 3);
    assert_count(1, $third['items'], 'the last page holds the remainder');

    $fourth = list_content_page('blog_post', 4, 3);
    assert_count(0, $fourth['items'], 'past the end renders empty, not an error');
    assert_eq(3, $fourth['pages'], 'and still reports the real page count');
});

t('list_content_page() never repeats or skips a row across pages', function () {
    $seen = [];

    for ($page = 1; $page <= 3; $page++) {
        foreach (list_content_page('blog_post', $page, 3)['items'] as $item) {
            $seen[] = (int) $item['id'];
        }
    }

    assert_eq(7, count($seen), 'every row appears exactly once');
    assert_eq(count($seen), count(array_unique($seen)), 'and no row appears twice');
});

t('list_content_page() hides drafts and future items', function () {
    seed_content([
        'type'         => 'blog_post',
        'slug'         => 'page-fixture-draft',
        'title'        => 'Draft fixture',
        'status'       => 'draft',
        'published_at' => null,
    ]);
    seed_content([
        'type'         => 'blog_post',
        'slug'         => 'page-fixture-future',
        'title'        => 'Future fixture',
        'status'       => 'published',
        'published_at' => time() + 86400,
    ]);

    $titles = array_column(list_content_page('blog_post', 1, 50)['items'], 'title');

    assert_false(in_array('Draft fixture', $titles, true), 'a draft is not listed');
    assert_false(in_array('Future fixture', $titles, true), 'a future item is not listed');
    assert_eq(7, list_content_page('blog_post', 1, 50)['total'], 'the total counts visible rows only');
});

t('list_content_page() refuses a type the theme does not declare', function () {
    // 'secret_type' is not in theme.php, so it must not be queryable even
    // though rows of that type could exist in the table.
    seed_content([
        'type'         => 'secret_type',
        'slug'         => 'not-listed',
        'title'        => 'Should not be listed',
        'status'       => 'published',
        'published_at' => time() - 5,
    ]);

    $result = list_content_page('secret_type', 1, 10);

    assert_eq(0, $result['total']);
    assert_count(0, $result['items']);
});

t('list_content_page() honours a per-page cap set by the caller', function () {
    assert_count(2, list_content_page('blog_post', 1, 2)['items']);
    assert_eq(2, list_content_page('blog_post', 1, 2)['per_page']);
});

t('pagination_is_paged_request() is true only beyond the first page', function () {
    foreach ([[], ['page' => '1'], ['page' => ''], ['page' => '0']] as $query) {
        pagination_get($query);
        assert_false(pagination_is_paged_request(), 'not paged: ' . json_encode($query));
    }

    foreach ([['page' => '2'], ['page' => '9']] as $query) {
        pagination_get($query);
        assert_true(pagination_is_paged_request(), 'paged: ' . json_encode($query));
    }

    pagination_get([]);
});

t('the canonical URL carries the current page', function () {
    $page = ['id' => 5, 'title' => 'Blog', 'slug' => 'blog', 'path' => 'blog'];

    pagination_get([]);
    assert_eq('/blog', seo_canonical_path($page), 'page 1 keeps the clean URL');

    pagination_get(['page' => '2']);
    assert_eq('/blog?page=2', seo_canonical_path($page), 'page 2 canonicalises to itself');

    pagination_get([]);
});

exit(test_summary());

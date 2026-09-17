<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Redirects and 404 tracking
|--------------------------------------------------------------------------
|
| The redirect table, the slug-change 301, and the 404 rows the redirects
| page suggests catching.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

function redirects_reset(): void
{
    db()->exec("DELETE FROM redirects");
    db()->exec("DELETE FROM page_views");

    foreach (glob(analytics_buffer_path() . '*') ?: [] as $file) {
        @unlink($file);
    }

    @unlink(STORAGE_PATH . '/.analytics-ingest');
}

t('paths are normalised and reserved routes are refused', function () {
    assert_eq('old/page', redirect_normalize_path('/old/page/'));
    assert_eq('old/page', redirect_normalize_path('https://example.com/old/page/?x=1'));
    assert_eq('', redirect_normalize_path('/'));

    assert_true(redirect_is_reserved('/admin/settings'));
    assert_true(redirect_is_reserved('media/logo.png'));
    assert_true(redirect_is_reserved('search'));
    assert_true(redirect_is_reserved('robots.txt'));
    assert_false(redirect_is_reserved('old/page'));
});

t('a saved redirect is found, updated in place and deleted', function () {
    redirects_reset();

    $id = redirect_save('/old-url/', '/new-url/', 301);
    assert_true($id > 0, 'a row id is returned');

    $found = redirect_find('old-url');
    assert_eq('/new-url/', $found['to_path']);
    assert_eq(301, (int) $found['status']);

    // Saving the same "from" updates rather than duplicating.
    redirect_save('old-url', 'https://example.com/elsewhere', 302);

    $found = redirect_find('old-url');
    assert_eq('https://example.com/elsewhere', $found['to_path']);
    assert_eq(302, (int) $found['status']);
    assert_eq(1, (int) db()->query("SELECT COUNT(*) FROM redirects")->fetchColumn(), 'still one row');

    assert_eq(null, redirect_find('never-added'));

    assert_true(redirect_delete($id));
    assert_eq(null, redirect_find('old-url'));
});

t('saving a redirect clears that path cache file', function () {
    redirects_reset();

    cache_write('old-url', '<html>stale</html>');
    assert_true(is_file(cache_file_for('old-url')), 'precondition: the page is cached');

    redirect_save('old-url', 'about');

    assert_false(is_file(cache_file_for('old-url')), 'the stale cache file is gone');
});

t('a hit is counted', function () {
    redirects_reset();
    redirect_save('hit-me', 'about');

    redirect_record_hit('hit-me');
    redirect_record_hit('/hit-me/');

    assert_eq(2, (int) db()->query("SELECT hits FROM redirects WHERE from_path = 'hit-me'")->fetchColumn());
});

t('changing a published slug keeps the old URL alive', function () {
    redirects_reset();

    $id       = seed_content(['slug' => 'old-slug', 'title' => 'Mover', 'status' => 'published', 'published_at' => time()]);
    $existing = load_content_by_id($id);

    save_content('page', 'new-slug', [
        'title'        => 'Mover',
        'status'       => 'published',
        'published_at' => $existing['published_at'],
        'meta'         => $existing['meta'],
        'body'         => $existing['body'],
    ], $id);

    $found = redirect_find('old-slug');
    assert_true($found !== null, 'a redirect was created');
    assert_eq('new-slug', $found['to_path']);
    assert_eq(301, (int) $found['status']);

    // Re-saving the same slug must not add a second one.
    $again = load_content_by_id($id);

    save_content('page', 'new-slug', [
        'title'        => 'Mover',
        'status'       => 'published',
        'published_at' => $again['published_at'],
        'meta'         => $again['meta'],
        'body'         => $again['body'],
    ], $id);

    assert_eq(1, (int) db()->query("SELECT COUNT(*) FROM redirects")->fetchColumn(), 'no duplicate redirect');

    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

t('404s are recorded but kept out of the page-view counts', function () {
    redirects_reset();

    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0';
    $_SERVER['HTTP_REFERER']    = '';
    $_SERVER['REMOTE_ADDR']     = '203.0.113.9';

    $_SERVER['REQUEST_URI'] = '/missing-page/';
    analytics_record_view(null, false, 404);

    $_SERVER['REQUEST_URI'] = '/present/';
    analytics_record_view(null, false, 200);

    analytics_ingest();

    assert_eq(1, (int) db()->query("SELECT COUNT(*) FROM page_views WHERE status = 404")->fetchColumn());
    assert_eq(1, analytics_views(30), 'only the 200 counts as a page view');

    $misses = analytics_recent_404s(30, 10);
    assert_eq(1, count($misses));
    assert_eq('/missing-page/', $misses[0]['path']);
    assert_eq(1, (int) $misses[0]['views']);
});

exit(test_summary());

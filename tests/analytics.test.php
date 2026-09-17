<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Analytics
|--------------------------------------------------------------------------
|
| Views are appended to a buffer file and moved into the database in batches.
| These checks pin what is stored (and what is deliberately never stored), how
| bots and referrers are treated, and the queries behind the dashboard.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

function analytics_reset(): void
{
    db()->exec("DELETE FROM page_views");

    foreach (glob(analytics_buffer_path() . '*') ?: [] as $file) {
        @unlink($file);
    }

    @unlink(STORAGE_PATH . '/.analytics-ingest');
}

t('a recorded view keeps the path, content id and hashes but never the IP', function () {
    analytics_reset();

    $_SERVER['REQUEST_URI']     = '/about/?ref=x';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0';
    $_SERVER['HTTP_REFERER']    = 'https://news.example.com/story?utm=secret';
    $_SERVER['REMOTE_ADDR']     = '203.0.113.9';

    analytics_record_view(7);

    // The view lands in the buffer, not the database.
    assert_eq(0, (int) db()->query("SELECT COUNT(*) FROM page_views")->fetchColumn(), 'not written to the database yet');
    assert_eq(1, count(file(analytics_buffer_path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 'the view is buffered');

    assert_eq(1, analytics_ingest(), 'the buffered view is ingested');

    $row = db()->query("SELECT * FROM page_views")->fetch();

    assert_eq('/about/', $row['path']);
    assert_eq(7, (int) $row['content_id']);
    assert_eq('news.example.com', $row['referrer_host'], 'only the referrer host is kept');
    assert_eq(0, (int) $row['is_bot']);
    assert_eq(0, (int) $row['cache_hit'], 'a rendered view is a cache miss');
    assert_eq(40, strlen((string) $row['visitor_hash']), 'a sha1 visitor hash');
    assert_eq(40, strlen((string) $row['ua_hash']));

    assert_not_contains('203.0.113.9', implode('|', array_map('strval', $row)), 'the IP is never stored');
    assert_not_contains('utm=secret', implode('|', array_map('strval', $row)), 'the referrer query string is never stored');
});

t('bot traffic is flagged, not silently dropped', function () {
    analytics_reset();

    $_SERVER['REQUEST_URI']     = '/';
    $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
    $_SERVER['HTTP_REFERER']    = '';
    $_SERVER['REMOTE_ADDR']     = '198.51.100.4';

    analytics_record_view();
    analytics_ingest();

    assert_eq(1, (int) db()->query("SELECT is_bot FROM page_views")->fetchColumn(), 'the view is stored with a bot flag');
    assert_eq(0, analytics_views(30), 'the dashboard counts humans only');
});

t('only the referrer host survives parsing', function () {
    $_SERVER['HTTP_HOST'] = 'mycms.local';

    assert_eq('example.com', analytics_referrer_host('https://example.com/a/b?token=secret'));
    assert_eq('www.example.com', analytics_referrer_host('https://WWW.Example.com/'));
    assert_eq(null, analytics_referrer_host('https://mycms.local/admin'), 'self-referrals are not referrers');
    assert_eq(null, analytics_referrer_host(''));
    assert_eq(null, analytics_referrer_host('not a url'));
});

t('the visitor hash is stable within a day and mixed with the user agent', function () {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

    $first  = analytics_visitor_hash('Firefox');
    $second = analytics_visitor_hash('Firefox');
    $other  = analytics_visitor_hash('Chrome');

    assert_eq($first, $second, 'the same visitor on the same day hashes the same');
    assert_true($first !== $other, 'a different user agent hashes differently');
    assert_eq(40, strlen($first));
});

t('dashboard queries count views, uniques, top pages and referrers', function () {
    analytics_reset();

    $now    = time();
    $insert = db()->prepare("
        INSERT INTO page_views (path, content_id, referrer_host, ua_hash, visitor_hash, is_bot, viewed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $rows = [
        ['/about/', 1, 'google.com', 'u1', 'v1', 0, $now],
        ['/about/', 1, 'google.com', 'u1', 'v1', 0, $now],
        ['/blog/',  2, 'news.example.com', 'u2', 'v2', 0, $now],
        ['/about/', 1, null, 'u1', 'v1', 0, $now],
        ['/bot/',   null, 'spam.example', 'b', 'b1', 1, $now],
        ['/old/',   null, 'old.example', 'o', 'o1', 0, $now - (40 * 86400)],
    ];

    foreach ($rows as $row) {
        $insert->execute($row);
    }

    assert_eq(4, analytics_views(30), 'bots and rows outside the window are excluded');
    assert_eq(2, analytics_unique_visitors(30), 'two distinct visitor hashes');
    assert_eq(4, analytics_views(7), 'the 7-day window sees the same recent rows');

    $pages = analytics_top_pages(30, 10);
    assert_eq('/about/', $pages[0]['path']);
    assert_eq(3, (int) $pages[0]['views']);
    assert_eq('/blog/', $pages[1]['path']);

    $referrers = analytics_top_referrers(30, 10);
    assert_eq('google.com', $referrers[0]['referrer_host']);
    assert_eq(2, (int) $referrers[0]['views']);
    assert_eq(2, count($referrers), 'direct views are not a referrer');

    $daily = analytics_daily_views(30);
    assert_eq(30, count($daily), 'one point per day');
    assert_eq(4, array_sum($daily), 'the daily points add up to the window total');
});

t('the sparkline is a self-contained inline SVG', function () {
    $svg = analytics_sparkline([0, 3, 1, 5]);

    assert_contains('<svg', $svg);
    assert_contains('<polyline', $svg);
    assert_not_contains('<script', $svg);
    assert_not_contains('http', $svg, 'nothing is loaded from outside');
});

t('the cache-hit ratio comes from the recorded views, not perf.log', function () {
    analytics_reset();

    $_SERVER['REQUEST_URI']     = '/about/';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0';
    $_SERVER['HTTP_REFERER']    = '';
    $_SERVER['REMOTE_ADDR']     = '203.0.113.9';

    analytics_record_view(null, true);  // served from the cache
    analytics_record_view(null, true);
    analytics_record_view(null, false); // rendered fresh
    analytics_ingest();

    assert_eq(2, (int) db()->query("SELECT SUM(cache_hit) FROM page_views")->fetchColumn(), 'two cache hits are stored');

    $ratio = analytics_cache_hit_ratio();

    assert_eq(2, $ratio['hits']);
    assert_eq(3, $ratio['total']);
    assert_eq(2 / 3, $ratio['ratio']);

    analytics_reset();
    assert_eq(null, analytics_cache_hit_ratio(), 'no views means no ratio');
});

t('analytics_clear() removes every recorded view', function () {
    analytics_reset();

    $insert = db()->prepare("
        INSERT INTO page_views (path, content_id, referrer_host, ua_hash, visitor_hash, is_bot, viewed_at)
        VALUES (?, NULL, NULL, 'u', 'v', 0, ?)
    ");
    $insert->execute(['/a/', time()]);
    $insert->execute(['/b/', time()]);

    // A buffered view must not survive the reset either.
    $_SERVER['REQUEST_URI'] = '/buffered/';
    analytics_record_view();
    assert_true(is_file(analytics_buffer_path()), 'a view is buffered');

    assert_eq(2, analytics_clear(), 'every row is removed');
    assert_eq(0, (int) db()->query("SELECT COUNT(*) FROM page_views")->fetchColumn());
    assert_false(is_file(analytics_buffer_path()), 'the buffer is cleared too');
});

t('a window can be offset to the previous period', function () {
    analytics_reset();

    $now    = time();
    $insert = db()->prepare("
        INSERT INTO page_views (path, content_id, referrer_host, ua_hash, visitor_hash, is_bot, cache_hit, viewed_at)
        VALUES (?, NULL, NULL, 'u', 'v', 0, 0, ?)
    ");

    $insert->execute(['/now/', $now]);
    $insert->execute(['/previous/', $now - (40 * 86400)]);
    $insert->execute(['/ancient/', $now - (100 * 86400)]);

    assert_eq(1, analytics_views(30), 'the current window sees only the recent row');
    assert_eq(1, analytics_views(30, 30), 'the previous window sees the 40-day-old row');
    assert_eq(0, analytics_views(30, 60), 'two windows back is empty');

    assert_eq(null, analytics_change(1, 0), 'a zero baseline has no percentage');
    assert_eq(100.0, analytics_change(2, 1), 'doubling is +100%');
    assert_eq(-50.0, analytics_change(1, 2), 'halving is -50%');
});

t('analytics_maybe_ingest() batches on a marker', function () {
    analytics_reset();

    $_SERVER['REQUEST_URI']     = '/buffered/';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0';
    $_SERVER['HTTP_REFERER']    = '';
    $_SERVER['REMOTE_ADDR']     = '203.0.113.9';

    analytics_record_view();
    analytics_maybe_ingest();

    assert_eq(1, (int) db()->query("SELECT COUNT(*) FROM page_views")->fetchColumn(), 'the first call ingests');

    analytics_record_view();
    analytics_maybe_ingest();

    assert_eq(1, (int) db()->query("SELECT COUNT(*) FROM page_views")->fetchColumn(), 'the marker throttles the next call');
    assert_true(is_file(analytics_buffer_path()), 'the second view is still buffered');

    analytics_reset();
});

t('the buffer is capped so a down database cannot grow it forever', function () {
    analytics_reset();

    $buffer = analytics_buffer_path();
    file_put_contents($buffer, str_repeat('x', analytics_buffer_limit() + 1));
    clearstatcache(true, $buffer);

    $_SERVER['REQUEST_URI'] = '/overflow/';
    analytics_record_view();

    clearstatcache(true, $buffer);
    assert_eq(analytics_buffer_limit() + 1, (int) filesize($buffer), 'the view is dropped once the buffer is full');

    analytics_reset();
});

t('a batch orphaned by a crash is recovered on the next ingest', function () {
    analytics_reset();

    $_SERVER['REQUEST_URI']     = '/orphan/';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0';
    $_SERVER['HTTP_REFERER']    = '';
    $_SERVER['REMOTE_ADDR']     = '203.0.113.9';

    // What a process that died between the rename and the insert leaves behind.
    analytics_record_view();
    $orphan = analytics_buffer_path() . '.99999.staging';
    rename(analytics_buffer_path(), $orphan);
    assert_true(is_file($orphan), 'precondition: an orphan exists');

    assert_eq(1, analytics_ingest(), 'the orphan is ingested');
    assert_false(is_file($orphan), 'the orphan is removed');
    assert_eq('/orphan/', db()->query("SELECT path FROM page_views")->fetchColumn());

    analytics_reset();
});

exit(test_summary());

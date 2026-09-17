<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Analytics
|--------------------------------------------------------------------------
|
| Views are buffered and written in one shutdown flush. These checks pin
| what is stored (and what is deliberately never stored), how bots and
| referrers are treated, and the queries behind the dashboard.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

function analytics_reset(): void
{
    db()->exec("DELETE FROM page_views");
    $GLOBALS['cms_page_views'] = [];
    $GLOBALS['cms_page_views_registered'] = false;
}

t('a recorded view keeps the path, content id and hashes but never the IP', function () {
    analytics_reset();

    $_SERVER['REQUEST_URI']     = '/about/?ref=x';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0';
    $_SERVER['HTTP_REFERER']    = 'https://news.example.com/story?utm=secret';
    $_SERVER['REMOTE_ADDR']     = '203.0.113.9';

    analytics_record_view(7);

    // Nothing is written until the shutdown flush.
    assert_eq(0, (int) db()->query("SELECT COUNT(*) FROM page_views")->fetchColumn(), 'buffered, not written yet');

    analytics_flush();

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
    analytics_flush();

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
    analytics_flush();

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

    assert_eq(2, analytics_clear(), 'every row is removed');
    assert_eq(0, (int) db()->query("SELECT COUNT(*) FROM page_views")->fetchColumn());
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

exit(test_summary());

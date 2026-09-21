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

    // The rename itself has to land: a redirect is served before routing, so a
    // page that kept its old slug would be sent away from its own live URL to a
    // path that does not exist.
    $moved = load_content_by_id($id);
    assert_eq('new-slug', $moved['slug'], 'the new slug is written');

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

// ---------------------------------------------------------------------------
// Conflict detection
// ---------------------------------------------------------------------------

t('a redirect cannot take a route or a live page away', function () {
    redirects_reset();

    $rules = static function (string $from, string $to): array {
        return array_column(redirect_conflicts($from, $to), 'rule');
    };

    assert_true(in_array('reserved', $rules('admin', 'about'), true), 'the admin area is a route');
    assert_true(in_array('reserved', $rules('index.php', 'about'), true), 'so is the front controller');
    assert_true(in_array('reserved', $rules('media/logo.png', 'about'), true), 'and everything under media');

    // The demo's pages are live, so a redirect would hide them.
    assert_true(in_array('shadows', $rules('about', 'contact'), true), 'a published page is live content');
    assert_true(in_array('shadows', $rules('blog/welcome-to-our-blog', 'about'), true), 'so is a published post');
    assert_true(in_array('shadows', $rules('category/news', 'about'), true), 'and a taxonomy archive');
    assert_true(in_array('shadows', $rules('portfolio/project-one', 'about'), true), 'and a portfolio item');

    // A draft is not live: its URL is exactly what a redirect is for.
    seed_content(['slug' => 'redirect-draft', 'status' => 'draft', 'published_at' => null]);

    assert_eq([], $rules('redirect-draft', 'about'), 'a draft path is free to redirect');

    // Trashed content is not live either.
    $trashed = seed_content(['slug' => 'redirect-trashed']);
    trash_content($trashed);

    assert_eq([], $rules('redirect-trashed', 'about'), 'a trashed path is free to redirect');

    assert_eq([], $rules('some-old-url', 'about'), 'a free path is accepted');
    assert_eq([], $rules('some-old-url', 'https://example.com/x'), 'and so is another site');
});

t('loops and self-targets are refused', function () {
    redirects_reset();

    $rules = static function (string $from, string $to): array {
        return array_column(redirect_conflicts($from, $to), 'rule');
    };

    assert_true(in_array('self', $rules('same-url', 'same-url'), true), 'a path cannot point at itself');
    assert_true(in_array('self', $rules('same-url', '/same-url/'), true), 'slashes do not make it different');

    redirect_save('loop-b', 'loop-c');
    redirect_save('loop-c', 'loop-d');

    assert_true(in_array('loop', $rules('loop-d', 'loop-b'), true), 'a three-step loop is refused');
    assert_true(in_array('loop', $rules('loop-c', 'loop-b'), true), 'and so is a shorter one');
    assert_true(in_array('self', $rules('loop-e', 'loop-e'), true), 'a one-step loop is a self-target');

    // A chain is allowed: it works, it just costs a hop.
    $chain = $rules('chain-a', 'loop-c');
    assert_false(in_array('loop', $chain, true), 'a longer chain is not a loop');
    assert_eq([], $chain, 'and is accepted');
});

t('redirect_save itself refuses a redirect that cannot work', function () {
    redirects_reset();

    $refused = 0;

    foreach ([['admin', 'about'], ['about', 'contact'], ['self-url', 'self-url']] as [$from, $to]) {
        try {
            redirect_save($from, $to);
        } catch (RuntimeException $exception) {
            $refused++;
        }
    }

    assert_eq(3, $refused, 'each one throws rather than writing a broken row');
    assert_eq(0, (int) db()->query("SELECT COUNT(*) FROM redirects")->fetchColumn(), 'and nothing was stored');

    // Replacing the entry that owns the path is still allowed: editing relies on it.
    $id = redirect_save('free-url', 'about');
    assert_eq($id, redirect_save('free-url', 'contact'), 'saving the same path updates in place');

    // An existing entry is reported, and the caller can ignore its own row.
    assert_true(in_array('existing', array_column(redirect_conflicts('free-url', 'pricing'), 'rule'), true));
    assert_eq([], redirect_conflicts('free-url', 'pricing', $id), 'editing does not report itself');
});

t('renaming a page back does not leave a loop', function () {
    redirects_reset();

    $id       = seed_content(['slug' => 'there-and-back', 'status' => 'published', 'published_at' => time()]);
    $existing = load_content_by_id($id);

    $save = static function (string $slug) use ($id, $existing): void {
        save_content('page', $slug, [
            'title'        => 'Mover',
            'status'       => 'published',
            'published_at' => $existing['published_at'],
            'meta'         => $existing['meta'],
            'body'         => $existing['body'],
        ], $id);
    };

    $save('moved-away');
    assert_eq('moved-away', redirect_find('there-and-back')['to_path'], 'the first move keeps the old URL alive');

    $save('there-and-back');

    assert_eq(null, redirect_find('there-and-back'), 'the stale entry is gone: that path is a page again');
    assert_eq('there-and-back', redirect_find('moved-away')['to_path'], 'and the move back is recorded');

    // The URL serves, rather than redirecting away from itself.
    assert_eq(null, redirect_find('there-and-back'), 'nothing owns the live path');
    assert_eq([], array_column(redirect_audit(), 'kind'), 'and the table has nothing to repair');
});

t('publishing on a redirected path clears the redirect', function () {
    redirects_reset();

    redirect_save('coming-soon', 'about');

    $id = seed_content(['slug' => 'coming-soon', 'status' => 'draft', 'published_at' => null]);
    assert_true(redirect_find('coming-soon') !== null, 'precondition: the redirect owns the path');

    $draft = load_content_by_id($id);

    save_content('page', 'coming-soon', [
        'title'        => 'Coming soon',
        'status'       => 'published',
        'published_at' => time(),
        'meta'         => $draft['meta'],
        'body'         => $draft['body'],
    ], $id);

    assert_eq(null, redirect_find('coming-soon'), 'the live page is not hidden by the old entry');
});

// ---------------------------------------------------------------------------
// Search, audit and repair
// ---------------------------------------------------------------------------

t('the list can be searched by either path', function () {
    redirects_reset();

    redirect_save('old-news', 'blog');
    redirect_save('old-shop', 'pricing');
    redirect_save('legacy-page', 'old-news');

    assert_eq(3, count(redirect_all()), 'no filter lists everything');
    assert_eq(1, count(redirect_all(['q' => 'old-shop'])), 'the old path matches');
    assert_eq(1, count(redirect_all(['q' => 'legacy'])), 'and so does a partial');
    assert_eq(1, count(redirect_all(['q' => 'blog'])), 'the new path matches too');

    // 'old-' hits both sources and legacy-page's target.
    assert_eq(3, count(redirect_all(['q' => 'old-'])), 'either side of the entry counts');
    assert_eq(0, count(redirect_all(['q' => 'nothing-here'])), 'no match is empty');
});

t('the audit finds what cannot work and the repair removes only that', function () {
    redirects_reset();

    // Written straight into the table: these are what an older install carries,
    // and redirect_save() now refuses to create them.
    $insert = db()->prepare("
        INSERT INTO redirects (from_path, to_path, status, hits, created_at)
        VALUES (:from_path, :to_path, 301, 0, :now)
    ");
    $now = time();

    foreach ([
        ['self-loop', 'self-loop'],
        ['loop-one', 'loop-two'],
        ['loop-two', 'loop-one'],
        ['about', 'contact'],          // shadows a live page
        ['admin', 'dashboard'],        // a route
        ['chain-start', 'chain-middle'],
        ['chain-middle', 'chain-end'],
    ] as [$from, $to]) {
        $insert->execute(['from_path' => $from, 'to_path' => $to, 'now' => $now]);
    }

    $kinds = array_column(redirect_audit(), 'kind');

    assert_true(in_array('self', $kinds, true), 'a self-target is reported');
    assert_true(in_array('loop', $kinds, true), 'so is a loop');
    assert_true(in_array('shadows', $kinds, true), 'so is a hidden page');
    assert_true(in_array('reserved', $kinds, true), 'so is a route');
    assert_true(in_array('chain', $kinds, true), 'a chain is reported too');

    // Both halves of the loop are findings of their own, so five rows go: the
    // self-target, the two loop entries, the hidden page and the route.
    assert_eq(5, redirect_repair(), 'everything that cannot work is removed');

    $left = array_column(redirect_all(), 'from_path');

    assert_true(in_array('chain-start', $left, true), 'a chain is kept: it works');
    assert_true(in_array('chain-middle', $left, true), 'both ends of it');
    assert_false(in_array('self-loop', $left, true), 'the broken entries are gone');
    assert_false(in_array('about', $left, true), 'including the one that hid a page');

    assert_eq(['chain'], array_values(array_unique(array_column(redirect_audit(), 'kind'))), 'only the chain warning remains');
});

t('the redirect search treats % and _ literally', function () {
    redirect_save('literal-percent-path', 'about');

    assert_true(count(redirect_all(['q' => 'literal'])) >= 1, 'a normal search still works');
    assert_count(0, redirect_all(['q' => '%%']), '%% is not a match-everything wildcard');
});

t('a taxonomy slug change keeps its old archive URL alive', function () {
    $id = redirect_record_taxonomy_slug_change('category', 'old-topic', 'new-topic');

    assert_true($id !== null, 'a redirect is recorded for the old archive');
    assert_eq('category/new-topic', redirect_find('category/old-topic')['to_path'], 'and points at the new one');

    // An undeclared taxonomy cannot produce an archive URL.
    assert_eq(null, redirect_record_taxonomy_slug_change('not-a-taxonomy', 'a', 'b'), 'an undeclared taxonomy records nothing');

    db()->prepare("DELETE FROM redirects WHERE from_path = ?")->execute(['category/old-topic']);
});

exit(test_summary());

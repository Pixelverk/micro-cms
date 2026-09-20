<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cache warm-up
|--------------------------------------------------------------------------
|
| The Utilities warm-up renders every published page into storage/cache.
| These checks pin that published pages (and the front page) land in the
| cache, and that drafts never do.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('warm_cache() renders published pages and the front page', function () {
    test_clear_cache_files();

    $publishedId = seed_content([
        'slug'         => 'warm-published',
        'title'        => 'Warm Published',
        'status'       => 'published',
        'published_at' => time(),
    ]);

    $draftId = seed_content([
        'slug'   => 'warm-draft',
        'title'  => 'Warm Draft',
        'status' => 'draft',
    ]);

    $result = warm_cache();

    assert_true($result['rendered'] > 0, 'at least one page is warmed');
    assert_false(in_array('warm-published', $result['failed'], true), 'the published page renders');

    $cacheFile = STORAGE_PATH . '/cache/warm-published.html';
    assert_true(is_file($cacheFile), 'the published page is cached');
    assert_contains('Warm Published', (string) file_get_contents($cacheFile), 'the cache holds the rendered page');

    assert_false(is_file(STORAGE_PATH . '/cache/warm-draft.html'), 'a draft is never warmed');

    assert_true(is_file(STORAGE_PATH . '/cache/home.html'), 'the front page is warmed');

    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $publishedId]);
    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $draftId]);
});

t('warmed pages contain no response-only preview markup', function () {
    test_clear_cache_files();

    warm_cache();

    $html = (string) file_get_contents(STORAGE_PATH . '/cache/home.html');

    // warm_cache() runs inside an admin request; it must render the visitor
    // page, not an editor's preview.
    assert_not_contains('cms-preview-bar', $html);
});

t('static URLs are rewritten relative to the page depth', function () {
    $html = "<link href='/theme/assets/style.css'><img src='/media/7.webp'>";

    assert_contains("href='theme/assets/style.css'", static_rewrite_urls($html, '', ''));
    assert_contains("src='media/7.webp'", static_rewrite_urls($html, '', ''));

    $nested = static_rewrite_urls($html, '', 'blog/post-one');
    assert_contains("href='../../theme/assets/style.css'", $nested);
    assert_contains("src='../../media/7.webp'", $nested);

    // A subfolder install also strips its base path.
    assert_contains(
        "href='../theme/assets/style.css'",
        static_rewrite_urls("<link href='/cms/theme/assets/style.css'>", '/cms', 'about')
    );

    // Every entry after the first in a srcset is space-separated.
    assert_contains(
        "srcset='media/a.webp 320w, media/b.webp 640w'",
        static_rewrite_urls("<img srcset='/media/a.webp 320w, /media/b.webp 640w'>", '', '')
    );

    // Theme assets are stamped with their file mtime, so the rewritten URL
    // keeps its query string; the exported file has no query either way.
    assert_contains(
        "href='theme/assets/style.css?v=1700000000'",
        static_rewrite_urls("<link href='/theme/assets/style.css?v=1700000000'>", '', '')
    );

    // An external URL that merely contains /media/ must survive untouched.
    $external = "<img src='https://cdn.example.com/media/hero.webp'>";
    assert_contains("src='https://cdn.example.com/media/hero.webp'", static_rewrite_urls($external, '', ''));
});

t('static_export_entries() lays pages out as pretty paths with assets', function () {
    test_clear_cache_files();
    warm_cache();

    $entries = static_export_entries();
    $names   = array_column($entries, 'name');

    assert_true(in_array('index.html', $names, true), 'the front page is the root index.html');
    assert_true(in_array('about/index.html', $names, true), 'pages keep their URL path');
    assert_true(in_array('theme/assets/style.css', $names, true), 'theme assets are included');
    assert_false(in_array('home/index.html', $names, true), 'the front page is not duplicated under its slug');

    foreach ($entries as $entry) {
        if ($entry['name'] === 'index.html') {
            assert_contains("href='theme/assets/", $entry['content'], 'asset URLs are relative');
            assert_not_contains("href='/theme/assets/", $entry['content'], 'no absolute asset URLs remain');
        }
    }
});

t('export_static_site() refuses without a backend, or writes an archive', function () {
    test_clear_cache_files();

    if (!zip_available()) {
        try {
            export_static_site();
        } catch (RuntimeException $exception) {
            assert_contains('zip', strtolower($exception->getMessage()));
            return;
        }

        throw new RuntimeException('expected export_static_site() to refuse without a zip backend');
    }

    $archive = export_static_site();
    assert_true(is_file($archive), 'an archive is written');
    assert_true(in_array('index.html', zip_entry_names($archive), true), 'the archive contains the front page');

    @unlink($archive);
});

t('warm_cache() reports pages it could not write to the cache', function () {
    test_clear_cache_files();

    $directory = STORAGE_PATH . '/cache';
    $original  = fileperms($directory) & 0777;

    chmod($directory, 0555);

    // A privileged test user (root) ignores directory permissions.
    if (is_writable($directory)) {
        chmod($directory, $original);
        return;
    }

    try {
        $result = warm_cache();

        assert_eq(0, $result['rendered'], 'nothing is counted as written');
        assert_true(count($result['failed']) > 0, 'the failures are reported');
    } finally {
        chmod($directory, $original);
    }
});

exit(test_summary());

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

/*
|--------------------------------------------------------------------------
| Content package
|--------------------------------------------------------------------------
| The theme's demo content ships as these two documents, and the same pair
| moves live content between installs. The round trip has to be exact, and a
| package this theme cannot render has to be refused rather than half-imported.
|
*/

t('the theme demo package loads and matches the installed demo', function () {
    $demo = content_package_theme_demo();

    assert_count(0, $demo['errors'], 'the shipped demo files parse: ' . implode('; ', $demo['errors']));

    $merged = content_package_merge($demo['documents']);
    assert_count(0, $merged['problems'], 'and merge: ' . implode('; ', $merged['problems']));

    $plan = content_package_plan($merged['package']);
    assert_count(0, $plan['problems'], 'and validate: ' . implode('; ', $plan['problems']));

    // The installer seeds from exactly these files, so the counts have to be
    // the ones a fresh install has.
    assert_eq(demo_content_count(), $plan['create']['content'], 'the demo ships every item');
    assert_eq(2, $plan['create']['menus'], 'and two menus');
    assert_eq('page:home', $plan['homepage']['ref']);
    assert_true($plan['homepage']['resolves'], 'the homepage resolves inside the package');
});

t('the demo content exercises every layout the theme declares', function () {
    $theme   = theme_config();
    $merged  = content_package_merge(content_package_theme_demo()['documents']);
    $package = $merged['package'];

    // Page layouts: everything an editor can choose except search, which
    // belongs to the search route rather than to a page.
    $used = [];

    foreach ($package['content'] as $item) {
        $used[(string) ($item['layout'] ?? '')] = true;
    }

    $missing = [];

    foreach (array_keys($theme['layouts']) as $layout) {
        if ($layout !== 'search' && !isset($used[$layout])) {
            $missing[] = $layout;
        }
    }

    assert_count(0, $missing, 'layouts no demo page uses: ' . implode(', ', $missing));

    // Archive layouts are reached through terms, one per taxonomy: the blog's
    // own archive and the generic fallback both need demo terms.
    $taxonomyLayouts = [];

    foreach (theme_taxonomies() as $name => $config) {
        $taxonomyLayouts[$name] = ($config['layout'] ?? '') !== '' ? $config['layout'] : 'taxonomy';
    }

    $archives = [];

    foreach ($package['taxonomies'] as $term) {
        $archives[$taxonomyLayouts[$term['type']] ?? 'taxonomy'] = true;
    }

    assert_true(isset($archives['blog-archive']), 'a term renders the blog archive layout');
    assert_true(isset($archives['taxonomy']), 'and a term of another taxonomy renders the generic one');
});

t('a package round trip reproduces the site it came from', function () {
    $beforeContent  = content_package_export_content();
    $beforeSettings = content_package_export_settings();

    // Replace the content with something else, the way another site would.
    db()->exec("DELETE FROM taxonomy_term_relationships");
    db()->exec("DELETE FROM content");
    db()->exec("DELETE FROM menus");
    set_setting('site_title', 'Somewhere Else');
    settings_cache_clear();

    $merged = content_package_merge([$beforeContent, $beforeSettings]);
    $plan   = content_package_plan($merged['package']);

    assert_count(0, $plan['problems'], implode('; ', $plan['problems']));
    assert_eq(1, $plan['create']['settings'], 'one setting differs before the import');
    assert_eq(demo_content_count(), $plan['create']['content']);

    $summary = content_package_import($merged['package']);

    assert_eq(demo_content_count(), $summary['content']);
    assert_eq(2, $summary['menus']);
    assert_eq('page:home', $summary['homepage']);

    // Timestamps travel with the package, so the documents come back identical.
    assert_eq($beforeContent, content_package_export_content(), 'content survives the round trip');
    assert_eq($beforeSettings, content_package_export_settings(), 'and so do settings');

    $indexed = db()->query("SELECT COUNT(*) FROM content WHERE search_text IS NOT NULL AND search_text != ''")->fetchColumn();
    assert_eq(demo_content_count(), (int) $indexed, 'the imported content is searchable');

    $homepageId = (int) (load_settings()['homepage_id'] ?? 0);
    $homepage   = load_content_by_id($homepageId);
    assert_eq('home', $homepage['slug'] ?? null, 'the homepage points at the imported home page');
});

t('content and settings import independently', function () {
    $contentDocument  = content_package_export_content();
    $settingsDocument = content_package_export_settings();

    // Settings only: the content stays as it is.
    set_setting('site_title', 'Keep Me');
    settings_cache_clear();

    $settingsOnly = content_package_merge([$settingsDocument]);
    assert_true($settingsOnly['package']['has_settings'], 'settings are recognised');
    assert_false($settingsOnly['package']['has_content'], 'and content is not implied');

    $countBefore = (int) db()->query("SELECT COUNT(*) FROM content")->fetchColumn();
    content_package_import($settingsOnly['package']);

    assert_eq($countBefore, (int) db()->query("SELECT COUNT(*) FROM content")->fetchColumn(), 'no content was touched');
    assert_eq('Awesome site', get_setting('site_title'), 'the setting was applied');

    // Content only: settings are left alone.
    set_setting('site_title', 'Keep Me Again');
    settings_cache_clear();

    $contentOnly = content_package_merge([$contentDocument]);
    $plan        = content_package_plan($contentOnly['package']);

    assert_count(0, $plan['problems'], implode('; ', $plan['problems']));
    assert_eq(0, $plan['create']['settings'], 'a content-only import changes no settings');

    content_package_import($contentOnly['package']);

    assert_eq('Keep Me Again', get_setting('site_title'), 'the setting survived a content import');
    assert_eq(demo_content_count(), (int) db()->query("SELECT COUNT(*) FROM content")->fetchColumn());
});

t('a package this theme cannot render is refused', function () {
    $document = content_package_export_content();

    // Three things the shipped theme does not have.
    $document['content'][1]['type'] = 'event';
    $document['content'][2]['layout'] = 'ghost-layout';
    $document['content'][3]['body'] = [
        ['type' => 'ghost-section', 'props' => [], 'children' => []],
    ];

    $merged = content_package_merge([$document]);
    $plan   = content_package_plan($merged['package']);
    $report = implode('; ', $plan['problems']);

    assert_contains("Unknown content type 'event'", $report, 'an unknown content type is named');
    assert_contains("undeclared layout 'ghost-layout'", $report, 'an undeclared layout is named');
    assert_contains("missing component 'ghost-section'", $report, 'a missing component is named');

    // A settings key that must never travel.
    $settings = content_package_export_settings();
    $settings['settings']['site_url'] = 'https://example.test';

    $settingsPlan = content_package_plan(content_package_merge([$settings])['package']);

    assert_contains("Setting 'site_url' cannot travel", implode('; ', $settingsPlan['problems']), 'an unportable setting is refused');

    // Nothing was written by any of that.
    assert_eq(demo_content_count(), (int) db()->query("SELECT COUNT(*) FROM content")->fetchColumn(), 'validation changes nothing');
});

t('two files defining the same section are refused', function () {
    $merged = content_package_merge([
        content_package_export_content(),
        content_package_export_content(),
    ]);

    assert_contains('Two files both define content', implode('; ', $merged['problems']));

    $settings = content_package_export_settings();
    $merged   = content_package_merge([$settings, $settings]);

    assert_contains('Two files both define settings', implode('; ', $merged['problems']));
});

t('a document that is not a package is rejected', function () {
    assert_contains('not JSON', content_package_parse('{oops')['error']);
    assert_contains('Unsupported package format', content_package_parse('{"content": []}')['error']);
    assert_contains('neither content nor settings', content_package_parse('{"format": 1}')['error']);
    assert_eq('', content_package_parse('{"format": 1, "settings": {}}')['error'], 'a settings-only file parses');
});

t('a homepage that cannot be resolved is skipped, not written', function () {
    $settings = content_package_export_settings();
    $settings['settings']['homepage'] = 'page:does-not-exist';

    // Settings only, so there is no content in the package to match against.
    $merged = content_package_merge([$settings]);
    $plan   = content_package_plan($merged['package']);

    assert_count(0, $plan['problems'], 'an unresolvable homepage is not fatal');
    assert_false($plan['homepage']['resolves']);
    assert_true($plan['warnings'] !== [], 'it is reported as a warning');

    $before = (int) (load_settings()['homepage_id'] ?? 0);
    content_package_import($merged['package']);

    assert_eq($before, (int) (load_settings()['homepage_id'] ?? 0), 'the homepage is left as it was');
});

t('the two package documents stay separate', function () {
    $content  = content_package_export_content();
    $settings = content_package_export_settings();

    assert_eq(CONTENT_PACKAGE_FORMAT, $content['format'] ?? null, 'the content document is versioned');
    assert_true(count($content['content'] ?? []) >= demo_content_count(), 'the content travels');
    assert_eq('page:home', $settings['settings']['homepage'] ?? null, 'and the homepage travels by slug');

    // One button downloads one document, so neither may smuggle in the other.
    assert_false(isset($content['settings']), 'content carries no settings');
    assert_false(isset($settings['content']), 'and settings carry no content');
});

t('a content-only import keeps the homepage pointing at the same page', function () {
    $before = (int) (load_settings()['homepage_id'] ?? 0);
    assert_true($before > 0, 'there is a homepage to begin with');

    // Replacing content replaces every id, so the homepage has to be
    // re-matched by path or '/' starts serving the 404 page.
    content_package_import(content_package_merge([content_package_export_content()])['package']);

    $after = (int) (load_settings()['homepage_id'] ?? 0);
    $page  = load_content_by_id($after);

    assert_true($after > 0, 'the homepage survived the import');
    assert_eq('home', $page['slug'] ?? null, 'and still points at the home page');

    // A package that has no such page clears the setting instead of leaving a
    // dangling id behind.
    $without = content_package_export_content();
    $without['content'] = array_values(array_filter(
        $without['content'],
        static fn(array $item): bool => ($item['path'] ?? '') !== 'home'
    ));

    $summary = content_package_import(content_package_merge([$without])['package']);

    assert_eq('', (string) (load_settings()['homepage_id'] ?? ''), 'the homepage is unset');
    assert_true($summary['warnings'] !== [], 'and the import says so');
});

exit(test_summary());

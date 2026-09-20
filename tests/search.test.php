<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Front-end search
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

/**
 * Create a published page with body text, index it, and return its id.
 */
function search_seed(string $slug, string $title, string $text, string $body = '', array $overrides = []): int
{
    $components = $body !== ''
        ? [['type' => 'quill-editor', 'props' => ['content' => $body], 'children' => []]]
        : [];

    $id = seed_content(array_merge([
        'slug'  => $slug,
        'title' => $title,
        'meta'  => ['description' => $text],
        'body'  => $components,
    ], $overrides));

    search_index_content($id);

    return $id;
}

t('the installer builds the search index', function () {
    // test_fresh_database() restores a template built by the real installer,
    // so this asserts what a fresh install ships with.
    $unindexed = (int) db()->query("SELECT COUNT(*) FROM content WHERE search_text IS NULL OR search_text = ''")->fetchColumn();

    assert_eq(0, $unindexed, 'every seeded item has searchable text');
});

t('search_extract_text() turns components into prose', function () {
    $body = [
        ['type' => 'hero-section', 'props' => ['title' => 'Hello', 'text' => '<p>World</p>'], 'children' => []],
    ];

    $text = search_extract_text($body);

    assert_contains('Hello', $text);
    assert_contains('World', $text);
    assert_not_contains('hero-section', $text, 'component names are not indexed');
    assert_not_contains('<p>', $text, 'markup is not indexed');
});

t('search_extract_text() separates block elements', function () {
    // strip_tags() alone would produce "find.That".
    $text = search_extract_text('<p>easy to find.</p><p>That principle guides us.</p>');

    assert_contains('find. That', $text);
    assert_not_contains('find.That', $text);
});

t('search_extract_text() skips non-prose props', function () {
    $body = ['type' => 'hero-section', 'props' => ['image' => 'hero.png', 'icon' => 'bi-star', 'title' => 'Real title']];

    $text = search_extract_text($body);

    assert_contains('Real title', $text);
    assert_not_contains('hero.png', $text, 'image references are not searchable');
    assert_not_contains('bi-star', $text, 'icon classes are not searchable');
});

t('search_build_text() includes the title, meta and body', function () {
    $text = search_build_text(
        [['type' => 'quill-editor', 'props' => ['content' => '<p>Body words</p>']]],
        ['description' => 'Description words', 'excerpt' => 'Excerpt words'],
        'Title words'
    );

    foreach (['Title words', 'Description words', 'Excerpt words', 'Body words'] as $expected) {
        assert_contains($expected, $text);
    }
});

t('saving content indexes it', function () {
    $id = search_seed('search-index', 'Indexed Page', 'A description', '<p>UniqueSearchableWord</p>');

    $stored = db()->prepare("SELECT search_text FROM content WHERE id = :id");
    $stored->execute(['id' => $id]);

    assert_contains('UniqueSearchableWord', (string) $stored->fetchColumn());
});

t('search_reindex_all() rebuilds the whole index', function () {
    db()->exec("UPDATE content SET search_text = NULL");

    $count = search_reindex_all();

    assert_true($count > 0, 'there is content to index');

    $unindexed = (int) db()->query("SELECT COUNT(*) FROM content WHERE search_text IS NULL OR search_text = ''")->fetchColumn();
    assert_eq(0, $unindexed, 'every item was rebuilt');

    assert_true(search_content('About Us')['total'] >= 1, 'seeded content is searchable after a rebuild');
});

t('search finds content by body text', function () {
    search_seed('search-body', 'Body Page', 'description', '<p>The quick brown fox jumps.</p>');

    $result = search_content('brown fox');

    assert_true($result['total'] >= 1, 'should match');
    assert_eq('Body Page', $result['items'][0]['title']);
});

t('search is case-insensitive and matches titles', function () {
    search_seed('search-case', 'Capitalised Title', 'nothing here');

    assert_true(search_content('capitalised')['total'] >= 1, 'lowercase query matches the title');
    assert_true(search_content('CAPITALISED')['total'] >= 1, 'uppercase query matches too');
});

t('short and empty queries are refused', function () {
    assert_false(search_query_is_valid(''));
    assert_false(search_query_is_valid('a'));
    assert_false(search_query_is_valid(' '));
    assert_true(search_query_is_valid('ab'));

    $result = search_content('a');
    assert_eq(0, $result['total'], 'a one-character query runs nothing');
});

t('unpublished content never appears in results', function () {
    $draft = search_seed('search-draft', 'Draft Unique', 'UniqueDraftDescription', '', [
        'status' => 'draft',
        'published_at' => null,
    ]);
    $archived = search_seed('search-archived', 'Archived Unique', 'UniqueArchivedDescription', '', [
        'status' => 'archived',
        'published_at' => null,
    ]);
    $future = search_seed('search-future', 'Future Unique', 'UniqueFutureDescription', '', [
        'published_at' => time() + 86400,
    ]);

    $_SESSION = [];

    assert_eq(0, search_content('UniqueDraftDescription')['total'], 'drafts are excluded');
    assert_eq(0, search_content('UniqueArchivedDescription')['total'], 'archived is excluded');
    assert_eq(0, search_content('UniqueFutureDescription')['total'], 'future-dated content is excluded');
});

t('an editor previewing can find unpublished content', function () {
    search_seed('search-preview-only', 'Preview Only', 'PreviewOnlyDescription', '', [
        'status' => 'draft',
        'published_at' => null,
    ]);

    test_preview_request();
    assert_true(search_content('PreviewOnlyDescription')['total'] >= 1, 'preview sees drafts');

    test_anonymous_request();
    assert_eq(0, search_content('PreviewOnlyDescription')['total'], 'visitors do not');
});

t('results can be filtered by content type', function () {
    search_seed('search-type-page', 'TypePageMarker', 'TypePageMarker', '');
    search_seed('search-type-post', 'TypePostMarker', 'TypePostMarker', '', ['type' => 'blog_post']);

    assert_true(search_content('TypePageMarker')['total'] >= 1);
    assert_eq(0, search_content('TypePageMarker', ['type' => 'blog_post'])['total'], 'type filter excludes pages');
    assert_true(search_content('TypePageMarker', ['type' => 'page'])['total'] >= 1);
});

t('results can be filtered by category and tag', function () {
    $pdo = db();
    $now = time();

    $pageId = search_seed('search-tax-page', 'TaxPageMarker', 'TaxPageMarker', '');

    // The demo seeds a 'news' blog category and a slug is unique per type, so
    // this fixture uses a slug of its own.
    $pdo->prepare("INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, created_at, updated_at) VALUES ('category', 'page', 'News', 'page-news', :now, :now)")
        ->execute(['now' => $now]);
    $categoryId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, created_at, updated_at) VALUES ('tag', 'page', 'Featured', 'featured', :now, :now)")
        ->execute(['now' => $now]);
    $tagId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO taxonomy_term_relationships (content_type, content_id, taxonomy_id) VALUES ('page', :id, :tax)")
        ->execute(['id' => $pageId, 'tax' => $categoryId]);
    $pdo->prepare("INSERT INTO taxonomy_term_relationships (content_type, content_id, taxonomy_id) VALUES ('page', :id, :tax)")
        ->execute(['id' => $pageId, 'tax' => $tagId]);

    assert_true(search_content('TaxPageMarker', ['category' => 'page-news'])['total'] >= 1, 'category filter matches');
    assert_true(search_content('TaxPageMarker', ['tag' => 'featured'])['total'] >= 1, 'tag filter matches');
    assert_eq(0, search_content('TaxPageMarker', ['category' => 'missing'])['total'], 'unknown term matches nothing');
});

t('results paginate', function () {
    for ($i = 1; $i <= 15; $i++) {
        search_seed('search-page-' . $i, "Paged Marker {$i}", 'PagedMarkerText', '');
    }

    $first = search_content('PagedMarkerText', [], 1);

    assert_true($first['total'] >= 15, 'all items counted');
    assert_eq(search_per_page(), count($first['items']), 'first page is full');
    assert_true($first['pages'] >= 2, 'more than one page');

    $second = search_content('PagedMarkerText', [], 2);
    assert_true(count($second['items']) > 0, 'second page has items');

    // Pages must not repeat rows.
    $firstIds = array_column($first['items'], 'id');
    $secondIds = array_column($second['items'], 'id');
    assert_count(0, array_intersect($firstIds, $secondIds), 'pages do not overlap');
});

t('search_excerpt() centres the match and truncates', function () {
    $text = str_repeat('filler ', 40) . 'needle' . str_repeat(' filler', 40);

    $excerpt = search_excerpt($text, 'needle', 60);

    assert_contains('needle', $excerpt);
    assert_true(mb_strlen($excerpt) < 90, 'excerpt is trimmed');
    assert_contains('…', $excerpt, 'truncation is marked');

    // A term that is absent falls back to the beginning.
    $noMatch = search_excerpt('short text', 'absent');
    assert_eq('short text', $noMatch);
});

t('search_highlight() marks matches without breaking escaping', function () {
    $highlighted = search_highlight(e('A <script> design'), 'design');

    assert_contains('<mark>design</mark>', $highlighted);
    assert_contains('&lt;script&gt;', $highlighted, 'the text stays escaped');
    assert_not_contains('<script>', $highlighted, 'no raw markup leaks in');
});

t('search_item_url() honours URL prefixes and the homepage', function () {
    $settings = load_settings();
    $theme = theme_config();

    $page = ['id' => 999, 'slug' => 'about', 'type' => 'page'];
    assert_eq(url('about/'), search_item_url($page));

    $post = ['id' => 998, 'slug' => 'hello', 'type' => 'blog_post'];
    $prefix = $settings['content_prefixes']['blog_post'] ?? $theme['content_types']['blog_post']['url_prefix'] ?? '';
    assert_contains(trim((string) $prefix, '/') . '/hello', search_item_url($post));

    if (!empty($settings['homepage_id'])) {
        $home = ['id' => (int) $settings['homepage_id'], 'slug' => 'home', 'type' => 'page'];
        assert_eq(url(''), search_item_url($home), 'the homepage resolves to the site root');
    }
});

t('search results are never indexable', function () {
    assert_contains('noindex', search_robots());
});

exit(test_summary());

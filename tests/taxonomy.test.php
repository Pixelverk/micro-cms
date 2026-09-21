<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taxonomies
|--------------------------------------------------------------------------
|
| The declaration layer: the core Category/Tag defaults, how a theme overrides
| or removes them, which content types offer which, and the read helpers the
| front end and theme use.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('the core defaults are category and tag', function () {
    $tax = theme_taxonomies([]);

    assert_true(isset($tax['category'], $tax['tag']), 'both defaults exist');
    assert_eq(false, $tax['category']['multiple'], 'a category is one term per item');
    assert_eq(true, $tax['tag']['multiple'], 'a tag is many');
    assert_eq('category', $tax['category']['url_prefix'], 'the prefix defaults to the name');
});

t('a theme can override, add and remove taxonomies', function () {
    $tax = theme_taxonomies([
        'category' => ['label_plural' => 'Sections', 'url_prefix' => 'section'],
        'tag'      => false,
        'topic'    => ['label' => 'Topic', 'url_prefix' => 'topic'],
    ]);

    assert_true(!isset($tax['tag']), 'false removes a default');
    assert_eq('section', $tax['category']['url_prefix'], 'an override wins');
    assert_eq('Sections', $tax['category']['label_plural'], 'the overridden label is kept');
    assert_eq('Category', $tax['category']['label'], 'an unmentioned default key survives');
    assert_eq(false, $tax['category']['multiple'], 'and so does its cardinality');
    assert_eq('Topic', $tax['topic']['label'], 'a new taxonomy is added');
    assert_eq(true, $tax['topic']['multiple'], 'and normalised to many by default');
});

t('each content type names the taxonomies it offers', function () {
    assert_eq(['category', 'tag'], content_type_taxonomies('blog_post'));
    assert_eq(['category'], content_type_taxonomies('portfolio_item'));
    assert_eq([], content_type_taxonomies('page'), 'a page offers none');
});

t('an unknown name in a content type is ignored', function () {
    // The shipped theme names only declared taxonomies; this pins the contract
    // that an undeclared one cannot leak into the editor.
    assert_eq([], content_type_taxonomies('does-not-exist'));
});

t('taxonomy_url() builds the archive URL from the declaration', function () {
    assert_eq('/category/news/', taxonomy_url('category', 'news'));
    assert_eq('/tag/web-design/', taxonomy_url('tag', 'web-design'));
});

t('the primary taxonomy is the single-term one by default', function () {
    assert_eq('category', taxonomy_primary_name());
});

t('a page carries its terms keyed by declared taxonomy name', function () {
    $page = load_content_by_slug('blog/welcome-to-our-blog');

    assert_true(is_array($page), 'the seeded post loads');

    $tax = content_taxonomies($page);

    assert_true(array_key_exists('category', $tax), 'category is a key');
    assert_true(array_key_exists('tag', $tax), 'tag is a key even when empty');
    assert_eq('News', $tax['category'][0]['name'] ?? null, 'the post carries its category');
    assert_eq('Web Design', $tax['tag'][0]['name'] ?? null, 'and its tag');
});

t('health reports the taxonomy declarations', function () {
    $byLabel = [];

    foreach (health_checks() as $check) {
        $byLabel[$check['label']] = $check['status'];
    }

    assert_eq('ok', $byLabel['Theme taxonomies'] ?? null, 'the shipped theme declares usable taxonomies');
});

t('a menu link can name a taxonomy', function () {
    $prepared = menu_items_prepare([
        ['type' => 'taxonomy', 'taxonomy' => 'category', 'label' => 'News', 'slug' => 'news', 'children' => []],
        ['type' => 'taxonomy', 'taxonomy' => 'topic', 'label' => 'Ghost', 'slug' => 'news', 'children' => []],
    ]);

    assert_eq(url('category/news'), $prepared[0]['url'], 'a declared taxonomy resolves to its archive');
    assert_eq(false, $prepared[0]['broken'], 'and the term exists');
    assert_eq(true, $prepared[1]['broken'], 'an undeclared taxonomy is broken');
});

t('the legacy category and tag menu kinds still resolve', function () {
    $prepared = menu_items_prepare([
        ['type' => 'category', 'label' => 'News', 'slug' => 'news', 'children' => []],
    ]);

    assert_eq(url('category/news'), $prepared[0]['url'], 'the legacy kind names its own taxonomy');
    assert_eq(false, $prepared[0]['broken'], 'and still resolves');
});

t('process_menu_items() keeps the taxonomy name', function () {
    $processed = process_menu_items([
        ['type' => 'taxonomy', 'taxonomy' => 'category', 'label' => 'News', 'slug' => 'news'],
    ]);

    assert_eq('taxonomy', $processed[0]['type'] ?? null, 'the kind survives a save');
    assert_eq('category', $processed[0]['taxonomy'] ?? null, 'and so does its taxonomy');
});

exit(test_summary());

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reusable content blocks
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

/**
 * A small valid component tree.
 */
function block_sample_tree(): array
{
    return [
        [
            'type'     => 'cta-section',
            'props'    => ['title' => 'Ready to start?', 'linktext' => 'Contact us'],
            'children' => [],
        ],
    ];
}

t('the blocks table exists on a fresh install', function () {
    $tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

    assert_true(in_array('blocks', $tables, true), 'blocks should exist');
    assert_true(blocks_enabled(), 'blocks are enabled by default');
});

t('save_block() creates a block and derives its slug', function () {
    $result = save_block(['label' => 'Hero Intro', 'tree' => block_sample_tree()]);

    assert_true($result['ok'], 'save should succeed');
    assert_eq('hero-intro', $result['slug']);

    $block = load_block((int) $result['id']);

    assert_true($block !== null);
    assert_eq('Hero Intro', $block['label']);
    assert_count(1, block_tree($block));
});

t('block slugs stay unique when labels collide', function () {
    $first = save_block(['label' => 'Duplicate Label', 'tree' => block_sample_tree()]);
    $second = save_block(['label' => 'Duplicate Label', 'tree' => block_sample_tree()]);

    assert_eq('duplicate-label', $first['slug']);
    assert_eq('duplicate-label-2', $second['slug'], 'the second gets a suffix');
    assert_true($first['id'] !== $second['id']);
});

t('a block needs a label and at least one component', function () {
    $noLabel = save_block(['label' => '', 'tree' => block_sample_tree()]);
    assert_false($noLabel['ok']);
    assert_true(isset($noLabel['errors']['label']));

    $noTree = save_block(['label' => 'Empty Block', 'tree' => []]);
    assert_false($noTree['ok']);
    assert_true(isset($noTree['errors']['tree']));
});

t('unknown components are dropped when saving', function () {
    $tree = [
        ['type' => 'cta-section', 'props' => [], 'children' => []],
        ['type' => 'not-a-real-component', 'props' => [], 'children' => []],
        ['type' => '', 'props' => [], 'children' => []],
    ];

    $result = save_block(['label' => 'Mixed', 'tree' => $tree]);
    assert_true($result['ok']);

    $stored = block_tree(load_block((int) $result['id']));

    assert_count(1, $stored, 'only the real component survives');
    assert_eq('cta-section', $stored[0]['type']);
});

t('component names are sanitised before lookup', function () {
    // The name is stripped to safe characters, so it no longer resolves to a
    // component and the block has nothing left to store.
    $result = save_block([
        'label' => 'Sanitise',
        'tree'  => [['type' => 'cta-section<script>', 'props' => [], 'children' => []]],
    ]);

    assert_false($result['ok'], 'a block with no valid components is refused');
    assert_true(isset($result['errors']['tree']));

    // A neighbouring valid component is still kept.
    $mixed = save_block([
        'label' => 'Sanitise Mixed',
        'tree'  => [
            ['type' => 'cta-section<script>', 'props' => [], 'children' => []],
            ['type' => 'cta-section', 'props' => ['title' => 'Kept'], 'children' => []],
        ],
    ]);

    assert_true($mixed['ok']);
    assert_count(1, block_tree(load_block((int) $mixed['id'])));
});

t('nesting is preserved and depth is bounded', function () {
    $nested = [
        [
            'type'     => 'features-section',
            'props'    => ['title' => 'Features'],
            'children' => [
                ['type' => 'feature-card', 'props' => ['title' => 'One'], 'children' => []],
                ['type' => 'feature-card', 'props' => ['title' => 'Two'], 'children' => []],
            ],
        ],
    ];

    $result = save_block(['label' => 'Nested', 'tree' => $nested]);
    assert_true($result['ok']);

    $stored = block_tree(load_block((int) $result['id']));

    assert_count(1, $stored);
    assert_count(2, $stored[0]['children'], 'children survive');
    assert_eq(3, blocks_count_components($stored));
});

t('a tree deeper than the limit is truncated', function () {
    // Build a chain longer than blocks_max_depth().
    $node = ['type' => 'feature-card', 'props' => [], 'children' => []];

    for ($i = 0; $i < blocks_max_depth() + 3; $i++) {
        $node = ['type' => 'features-section', 'props' => [], 'children' => [$node]];
    }

    $result = save_block(['label' => 'Too Deep', 'tree' => [$node]]);
    assert_true($result['ok'], 'it saves, but truncated');

    $stored = block_tree(load_block((int) $result['id']));

    // Walking down must terminate within the limit.
    $depth = 0;
    $cursor = $stored;

    while ($cursor) {
        $depth++;
        $cursor = $cursor[0]['children'] ?? [];
    }

    assert_true($depth <= blocks_max_depth() + 1, "depth {$depth} should be bounded");
});

t('save_block() updates an existing block', function () {
    $created = save_block(['label' => 'Original Name', 'tree' => block_sample_tree()]);
    $id = (int) $created['id'];

    $updated = save_block([
        'label'       => 'Renamed Block',
        'description' => 'Now with a description',
        'tree'        => [
            ['type' => 'cta-section', 'props' => ['title' => 'New'], 'children' => []],
            ['type' => 'faq-item', 'props' => ['question' => 'Q'], 'children' => []],
        ],
    ], $id);

    assert_true($updated['ok']);
    assert_eq($id, $updated['id'], 'the same row is updated');
    assert_eq('renamed-block', $updated['slug'], 'the slug follows the label');

    $block = load_block($id);
    assert_eq('Renamed Block', $block['label']);
    assert_eq('Now with a description', $block['description']);
    assert_count(2, block_tree($block));
});

t('delete_block() removes the row and reports failures', function () {
    $created = save_block(['label' => 'Temporary', 'tree' => block_sample_tree()]);
    $id = (int) $created['id'];

    assert_true(delete_block($id));
    assert_eq(null, load_block($id));

    assert_false(delete_block(999999), 'deleting a missing block returns false');
});

t('list_blocks() returns them alphabetically with their author', function () {
    test_login_session();

    save_block(['label' => 'Zebra Block', 'tree' => block_sample_tree()]);
    save_block(['label' => 'Alpha Block', 'tree' => block_sample_tree()]);

    $labels = array_column(list_blocks(), 'label');

    assert_true(in_array('Alpha Block', $labels, true));
    assert_eq('Alpha Block', $labels[0], 'sorted by label');

    $alpha = null;
    foreach (list_blocks() as $block) {
        if ($block['label'] === 'Alpha Block') {
            $alpha = $block;
        }
    }

    assert_eq('demo', $alpha['username'], 'the creator is joined for display');
});

t('blocks_for_type() only offers blocks the type can use', function () {
    // A block made of page-only components.
    save_block([
        'label' => 'Page Only',
        'tree'  => [['type' => 'pricing-section', 'props' => [], 'children' => []]],
    ]);

    $forPage = array_column(blocks_for_type('page'), 'label');
    $forPost = array_column(blocks_for_type('blog_post'), 'label');

    assert_true(in_array('Page Only', $forPage, true), 'offered to pages');

    $theme = theme_config();
    $postComponents = $theme['content_types']['blog_post']['available_components'] ?? [];

    if (!in_array('pricing-section', $postComponents, true)) {
        assert_false(in_array('Page Only', $forPost, true), 'not offered to blog posts');
    }
});

t('render_block() renders a stored tree through the component pipeline', function () {
    $created = save_block([
        'label' => 'Renderable',
        'tree'  => [[
            'type'     => 'cta-section',
            'props'    => ['title' => 'Rendered From Block', 'linktext' => 'Go'],
            'children' => [],
        ]],
    ]);

    $slug = $created['slug'];

    ob_start();
    render_block($slug, ['id' => 1, 'slug' => 'home', 'type' => 'page', 'components' => []]);
    $html = ob_get_clean();

    assert_contains('Rendered From Block', $html, 'the block renders');
});

t('render_block() is silent for an unknown slug', function () {
    ob_start();
    render_block('does-not-exist', []);
    $html = ob_get_clean();

    assert_eq('', $html, 'nothing is emitted');
});

t('block_slug_from_label() sanitises awkward labels', function () {
    $result = save_block(['label' => '  Fancy / Block & Co!  ', 'tree' => block_sample_tree()]);

    assert_true($result['ok']);
    assert_eq('fancy-block-co', $result['slug']);
});

exit(test_summary());

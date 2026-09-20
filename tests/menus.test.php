<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Menu slots
|--------------------------------------------------------------------------
|
| theme.php's menu_locations is the single source of truth for where a menu
| can appear. A component's schema points at one of those slots and renders
| whatever the admin assigned there. These checks keep the two halves honest:
| the editor offers exactly the declared slots, and resolution never silently
| substitutes a different menu.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('every menu slot declared by a component exists in the theme manifest', function () {
    $locations = theme_config()['menu_locations'] ?? [];
    assert_true($locations !== [], 'the theme should declare at least one menu location');

    $checked = 0;

    foreach (glob(CMS_PATH . '/theme/components/*.php') ?: [] as $file) {
        $component = require $file;
        $field = $component['schema']['menu'] ?? null;

        if ($field === null) {
            continue;
        }

        $name = basename($file);
        $checked++;

        assert_eq('select', $field['type'] ?? '', "{$name} menu field should be a select");
        assert_true(
            array_key_exists((string) ($field['default'] ?? ''), $locations),
            "{$name} default slot '{$field['default']}' is not declared in menu_locations"
        );
    }

    assert_true($checked >= 2, 'expected the header and footer to declare a menu slot');
});

t('the editor offers exactly the slots the theme declares', function () {
    $locations = theme_config()['menu_locations'] ?? [];
    $editor = (string) file_get_contents(CMS_PATH . '/admin/content/edit.php');

    assert_contains(
        "\$schema['menu']['options'] = \$theme['menu_locations']",
        $editor,
        'menu options should come from the manifest, not a hardcoded list'
    );

    // The manifest itself is the list the admin menu page renders.
    $menuPage = (string) file_get_contents(CMS_PATH . '/admin/menu/edit.php');
    assert_contains("\$theme['menu_locations']", $menuPage);
    assert_contains('name="locations[]"', $menuPage);

    assert_true(is_array($locations) && $locations !== []);
});

t('a declared slot resolves to the menu assigned to it', function () {
    $menu = get_menu_for_location('main');

    assert_eq('Main Menu', $menu['label']);
    assert_true(count($menu['items']) > 0, 'the seeded main menu should have items');
});

t('an unassigned slot falls back to a menu of the same name', function () {
    set_setting('menu_locations', []);

    $menu = get_menu_for_location('main');

    assert_eq('main', $menu['slug']);
    assert_eq('Main Menu', $menu['label']);
});

t('a slot with no assignment and no same-named menu resolves to an empty menu', function () {
    set_setting('menu_locations', []);

    $menu = get_menu_for_location('sidebar');

    assert_eq('', $menu['slug']);
    assert_count(0, $menu['items']);
});

t('of two empty menus, resolution uses the assignment rather than the fallback', function () {
    // 'header1' is not a slot the theme declares, so it can only be reached
    // through the assignment. The old 'header1' rescue ignored the assignment
    // and returned a same-named menu instead, which is what this guards.
    save_menu(['label' => 'Header One', 'slug' => 'header1', 'items' => []]);

    set_setting('menu_locations', ['footer' => 'header1']);

    $menu = get_menu_for_location('footer');

    assert_eq('header1', $menu['slug']);
    assert_eq('Header One', $menu['label']);
});

// ---------------------------------------------------------------------------
// Resolving links
// ---------------------------------------------------------------------------

t('a content link follows a renamed page', function () {
    $id = seed_content(['slug' => 'menu-rename', 'title' => 'Before']);

    $item = ['type' => 'page', 'label' => 'Before', 'slug' => 'menu-rename', 'content_id' => $id, 'children' => []];

    $prepared = menu_items_prepare([$item], '/menu-rename/');

    assert_eq(url('menu-rename'), $prepared[0]['url'], 'the id resolves to the page it names');
    assert_true($prepared[0]['current'], 'and is the page being served');
    assert_false($prepared[0]['broken']);

    // Rename it the way the editor does.
    save_content('page', 'menu-renamed', [
        'type'         => 'page',
        'title'        => 'After',
        'status'       => 'published',
        'published_at' => time(),
        'meta'         => [],
        'body'         => [],
    ], $id);

    $prepared = menu_items_prepare([$item], '/menu-renamed/');

    assert_eq(url('menu-renamed'), $prepared[0]['url'], 'the id follows the page, not the stored slug');
    assert_false($prepared[0]['broken'], 'so the link still works');
});

t('a nested page resolves to its full path', function () {
    $parent = seed_content(['slug' => 'menu-parent', 'title' => 'Parent']);
    $child  = seed_content(['slug' => 'menu-child', 'title' => 'Child', 'parent_id' => $parent]);

    // The stored slug is the leaf, which used to be the whole URL.
    $item = ['type' => 'page', 'label' => 'Child', 'slug' => 'menu-child', 'content_id' => $child, 'children' => []];

    $prepared = menu_items_prepare([$item]);

    assert_eq(url('menu-parent/menu-child'), $prepared[0]['url'], 'the parent path is part of the URL');
});

t('an item without an id still resolves by its slug', function () {
    // What every menu written before ids existed looks like.
    $item = ['type' => 'page', 'label' => 'About', 'slug' => 'about', 'children' => []];

    $prepared = menu_items_prepare([$item]);

    assert_eq(url('about'), $prepared[0]['url']);
    assert_false($prepared[0]['broken'], 'a legacy item is not reported as broken');
});

t('a link to a page that is gone is broken, not a dead URL', function () {
    $id = seed_content(['slug' => 'menu-trashed', 'title' => 'Trashed']);

    $item = ['type' => 'page', 'label' => 'Trashed', 'slug' => 'menu-trashed', 'content_id' => $id, 'children' => []];

    assert_false(menu_items_prepare([$item])[0]['broken'], 'precondition: it resolves first');

    trash_content($id);

    $prepared = menu_items_prepare([$item]);

    assert_true($prepared[0]['broken'], 'a trashed page leaves the link broken');
    assert_eq('', $prepared[0]['url'], 'and nothing to link to');
});

t('an archive link resolves, and a term that is gone is broken', function () {
    $live  = menu_items_prepare([['type' => 'category', 'label' => 'News', 'slug' => 'news', 'children' => []]]);
    $gone  = menu_items_prepare([['type' => 'category', 'label' => 'Ghost', 'slug' => 'not-a-term', 'children' => []]]);

    assert_eq(url('category/news'), $live[0]['url'], 'the demo taxonomy term resolves');
    assert_false($live[0]['broken']);
    assert_true($gone[0]['broken'], 'an unknown term is broken');
});

t('a custom URL is taken as written', function () {
    $prepared = menu_items_prepare([
        ['type' => 'url', 'label' => 'Elsewhere', 'slug' => 'https://example.com/x', 'children' => []],
        ['type' => 'url', 'label' => 'Group', 'slug' => '#', 'children' => []],
    ]);

    assert_eq('https://example.com/x', $prepared[0]['url']);
    assert_false($prepared[0]['broken']);
    assert_eq('#', $prepared[1]['url'], 'a hash keeps a group unclickable');
});

// ---------------------------------------------------------------------------
// Active state
// ---------------------------------------------------------------------------

t('the active trail marks the page and the section it sits in', function () {
    $items = [[
        'type'     => 'url',
        'label'    => 'Blog',
        'slug'     => '#',
        'children' => [
            ['type' => 'page', 'label' => 'Blog Home', 'slug' => 'blog', 'children' => []],
            ['type' => 'blog_post', 'label' => 'A post', 'slug' => 'welcome-to-our-blog', 'children' => []],
        ],
    ], [
        'type'  => 'page',
        'label' => 'About',
        'slug'  => 'about',
        'children' => [],
    ]];

    $prepared = menu_items_prepare($items, '/blog/welcome-to-our-blog/');

    assert_true($prepared[0]['active'], 'the section is active');
    assert_false($prepared[0]['current'], 'but it is not the page itself');
    assert_true($prepared[0]['children'][1]['current'], 'the page it names is current');
    assert_false($prepared[1]['active'], 'a page elsewhere is not active');
});

t('the site root is only ever active on itself', function () {
    $items = [['type' => 'url', 'label' => 'Home', 'slug' => '/', 'children' => []]];

    assert_true(menu_items_prepare($items, '/')[0]['active'], 'active on the front page');
    assert_false(menu_items_prepare($items, '/about/')[0]['active'], 'not active on every other page');
});

t('matching ignores the trailing slash and the query string', function () {
    $items = [['type' => 'url', 'label' => 'Blog', 'slug' => '/blog', 'children' => []]];

    assert_true(menu_items_prepare($items, '/blog/')[0]['current'], 'slash or no slash is the same page');
    assert_true(menu_items_prepare([['type' => 'url', 'label' => 'Search', 'slug' => '/search', 'children' => []]], '/search?q=launch')[0]['current'], 'the query is not part of the path');
});

// ---------------------------------------------------------------------------
// Hidden items
// ---------------------------------------------------------------------------

t('a hidden item takes its children with it', function () {
    $items = [
        ['type' => 'page', 'label' => 'Visible', 'slug' => 'about', 'children' => []],
        ['type' => 'page', 'label' => 'Parked', 'slug' => 'pricing', 'hidden' => true, 'children' => [
            ['type' => 'page', 'label' => 'Parked child', 'slug' => 'faq', 'children' => []],
        ]],
    ];

    $prepared = menu_items_prepare($items, '/pricing/');

    assert_count(1, $prepared, 'the hidden branch is gone');
    assert_eq('Visible', $prepared[0]['label']);

    // The editor still needs to see it.
    $context = ['rows' => [], 'terms' => []];
    assert_count(2, menu_items_resolve($items, $context, true), 'the editor keeps hidden items');
});

// ---------------------------------------------------------------------------
// Packages
// ---------------------------------------------------------------------------

t('a package carries menu links without ids, and import resolves them', function () {
    save_menu([
        'label' => 'Package Menu',
        'slug'  => 'package-menu',
        'items' => [[
            'type'       => 'page',
            'label'      => 'About',
            'slug'       => 'about',
            'content_id' => (int) db()->query("SELECT id FROM content WHERE slug = 'about' AND type = 'page'")->fetchColumn(),
            'children'   => [],
        ]],
    ]);

    $document = content_package_export_content();
    $exported = null;

    foreach ($document['menus'] as $menu) {
        if ($menu['slug'] === 'package-menu') {
            $exported = $menu;
        }
    }

    assert_true($exported !== null, 'the menu is in the package');
    assert_false(array_key_exists('content_id', $exported['items'][0]), 'ids never travel');
    assert_eq('about', $exported['items'][0]['slug'], 'the slug does');

    // Send it back to this install: the link has to point at its own row.
    $context = ['rows' => [], 'terms' => []];
    $attached = menu_items_attach_ids($exported['items']);

    assert_true(!empty($attached[0]['content_id']), 'import resolves the id from the slug');

    $prepared = menu_items_prepare($attached);
    assert_eq(url('about'), $prepared[0]['url']);
    assert_false($prepared[0]['broken']);
});

exit(test_summary());

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

exit(test_summary());

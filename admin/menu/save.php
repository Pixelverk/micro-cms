<?php
declare(strict_types=1);

// ----------------------------
// Read input
// ----------------------------
$menuSlug = trim($_POST['menu'] ?? '');
$label    = trim($_POST['label'] ?? '');
$location = trim($_POST['location'] ?? '');
$items    = $_POST['items'] ?? [];

$locations = theme_config()['menu_locations'] ?? [];
if (!array_key_exists($location, $locations)) {
    redirect_with_toast('menu/edit', 'error', 'Invalid menu location.');
}

// ----------------------------
// Validate menu slug
// ----------------------------
if ($menuSlug === '') {
    redirect_with_toast('menu/edit', 'error', 'Menu name is required.');
}

// Normalize menu slug
$menuSlug = strtolower($menuSlug);
$menuSlug = preg_replace('/[\s_]+/', '-', $menuSlug);
$menuSlug = preg_replace('/[^a-z0-9\-]/', '', $menuSlug);
$menuSlug = preg_replace('/-+/', '-', $menuSlug);
$menuSlug = trim($menuSlug, '-');

if ($menuSlug === '') {
    redirect_with_toast('menu/edit', 'error', 'Invalid menu name.');
}

// ----------------------------
// Load existing menu if any
// ----------------------------
$existingMenu = get_menu($menuSlug);

// ----------------------------
// Recursive function to process menu items
// ----------------------------
function processMenuItems(array $items): array {
    $result = [];
    foreach ($items as $item) {
        $type = $item['type'] ?? 'page';
        $entry = [
            'type'     => $type,
            'label'    => $item['label'] ?? '',
            'slug'     => $item['slug'] ?? '',
            'target'   => $item['target'] ?? '_self',
            'children' => [],
        ];

        if (!empty($item['children']) && is_array($item['children'])) {
            $entry['children'] = processMenuItems($item['children']);
        }

        $result[] = $entry;
    }
    return $result;
}

// ----------------------------
// Build menu data
// ----------------------------
$menuData = [
    'slug'  => $menuSlug,
    'label' => $label ?: ($existingMenu['label'] ?? ucfirst($menuSlug)),
    'items' => processMenuItems($items),
];

// ----------------------------
// Save menu using helper
// ----------------------------
if (!save_menu($menuData)) {
    redirect_with_toast(
        'menu/edit',
        'error',
        'Failed to save menu.',
        ['menu' => $menuSlug]
    );
}

$assignments = get_setting('menu_locations', []);
if (!is_array($assignments)) $assignments = [];
$assignments[$location] = $menuSlug;
set_setting('menu_locations', $assignments);

// ----------------------------
// Success
// ----------------------------
redirect_with_toast(
    'menu/edit',
    'success',
    "Menu \"{$menuData['label']}\" saved successfully.",
    ['menu' => $menuSlug]
);
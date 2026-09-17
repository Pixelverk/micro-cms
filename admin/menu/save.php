<?php
declare(strict_types=1);

// ----------------------------
// Read input
// ----------------------------
$menuSlug  = trim($_POST['menu'] ?? '');
$label     = trim($_POST['label'] ?? '');
$items     = $_POST['items'] ?? [];
$checked   = $_POST['locations'] ?? [];
$checked   = is_array($checked) ? $checked : [];

$locations = theme_config()['menu_locations'] ?? [];

// Only locations the theme declares, and no duplicates.
$checkedLocations = array_values(array_intersect(array_keys($locations), array_map('strval', $checked)));

// ----------------------------
// Validate menu slug
// ----------------------------
if ($menuSlug === '') {
    redirect_with_toast('menu/edit', 'error', admin_trans('menu_error_name_required'));
}

// Normalize menu slug
$menuSlug = strtolower($menuSlug);
$menuSlug = preg_replace('/[\s_]+/', '-', $menuSlug);
$menuSlug = preg_replace('/[^a-z0-9\-]/', '', $menuSlug);
$menuSlug = preg_replace('/-+/', '-', $menuSlug);
$menuSlug = trim($menuSlug, '-');

if ($menuSlug === '') {
    redirect_with_toast('menu/edit', 'error', admin_trans('menu_error_name_invalid'));
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
        admin_trans('menu_error_save'),
        ['menu' => $menuSlug]
    );
}

$assignments = get_setting('menu_locations', []);
if (!is_array($assignments)) {
    $assignments = [];
}

// This menu is assigned to exactly the locations that are checked. A location
// checked here is taken over from whatever menu held it before; one that is
// unchecked while held by this menu is released.
foreach (array_keys($locations) as $locationKey) {
    $isChecked = in_array($locationKey, $checkedLocations, true);
    $holdsIt   = ($assignments[$locationKey] ?? '') === $menuSlug;

    if ($isChecked) {
        $assignments[$locationKey] = $menuSlug;
    } elseif ($holdsIt) {
        unset($assignments[$locationKey]);
    }
}

set_setting('menu_locations', $assignments);

// ----------------------------
// Success
// ----------------------------
redirect_with_toast(
    'menu/edit',
    'success',
    admin_trans('menu_saved', ['name' => $menuData['label']]),
    ['menu' => $menuSlug]
);
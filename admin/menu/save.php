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
// `type` is a content type key, 'url' for a hand-written link, or 'category' /
// 'tag' for an archive. `content_id` is what lets a link follow a renamed page;
// the slug beside it is the fallback. Neither `content_id` nor `hidden` is
// written when it carries no information, so a plain item stays plain.
function processMenuItems(array $items): array {
    $theme = theme_config();
    $kinds = array_merge(
        ['url', 'category', 'tag'],
        array_keys($theme['content_types'] ?? [])
    );

    $result = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = (string) ($item['type'] ?? 'url');

        // An item whose kind this install does not know is treated as a custom
        // link rather than silently dropped.
        if (!in_array($type, $kinds, true)) {
            $type = 'url';
        }

        $entry = [
            'type'     => $type,
            'label'    => (string) ($item['label'] ?? ''),
            'slug'     => (string) ($item['slug'] ?? ''),
            'target'   => ($item['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
            'children' => [],
        ];

        $contentId = (int) ($item['content_id'] ?? 0);

        if ($contentId > 0) {
            $entry['content_id'] = $contentId;
        }

        if (!empty($item['hidden'])) {
            $entry['hidden'] = true;
        }

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
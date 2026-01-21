<?php
declare(strict_types=1);

// ----------------------------
// Read input
// ----------------------------
$menuSlug = trim($_POST['menu'] ?? '');
$label    = trim($_POST['label'] ?? '');
$items    = $_POST['items'] ?? [];

// ----------------------------
// Validate menu slug
// ----------------------------
if ($menuSlug === '') {
    redirect_with_toast('menu-edit', 'error', 'Menu name is required.');
}

// Normalize menu slug
$menuSlug = strtolower($menuSlug);
$menuSlug = preg_replace('/[\s_]+/', '-', $menuSlug);
$menuSlug = preg_replace('/[^a-z0-9\-]/', '', $menuSlug);
$menuSlug = preg_replace('/-+/', '-', $menuSlug);
$menuSlug = trim($menuSlug, '-');

if ($menuSlug === '') {
    redirect_with_toast('menu-edit', 'error', 'Invalid menu name.');
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
        'menu-edit',
        'error',
        'Failed to save menu.',
        ['menu' => $menuSlug]
    );
}

// ----------------------------
// Success
// ----------------------------
redirect_with_toast(
    'menu-edit',
    'success',
    "Menu \"{$menuData['label']}\" saved successfully.",
    ['menu' => $menuSlug]
);
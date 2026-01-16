<?php

// ----------------------------
// Read input
// ----------------------------
$menu  = trim($_POST['menu'] ?? '');
$label = trim($_POST['label'] ?? '');
$items = $_POST['items'] ?? [];

// ----------------------------
// Validate menu name
// ----------------------------
if ($menu === '') {
    redirect_with_toast('menu-edit', 'error', 'Menu name is required.');
}

// Normalize menu key
$menu = strtolower($menu);
$menu = preg_replace('/[\s_]+/', '-', $menu);
$menu = preg_replace('/[^a-z0-9\-]/', '', $menu); // allow letters, numbers, dash
$menu = preg_replace('/-+/', '-', $menu);
$menu = trim($menu, '-');

if ($menu === '') {
    redirect_with_toast('menu-edit', 'error', 'Invalid menu name.');
}

// ----------------------------
// Load existing menus
// ----------------------------
$menus = load_menus();

// ----------------------------
// Create menu if it doesn't exist
if (!isset($menus[$menu])) {
    $menus[$menu] = [
        'label' => $label ?: ucfirst($menu),
        'items' => []
    ];
} else {
    // Update label
    $menus[$menu]['label'] = $label ?: $menus[$menu]['label'];
}

// ----------------------------
// Recursive function to process menu items
// ----------------------------
function processMenuItems(array $items): array {
    $result = [];

    foreach ($items as $item) {
        $type = $item['type'] ?? 'page';

        $entry = [
            'type' => $type,
            'label' => $item['label'] ?? '',
            'target' => $item['target'] ?? '_self',
            'children' => []
        ];

        $entry['slug'] = $item['slug'] ?? '';

        // Recursively process children
        if (!empty($item['children']) && is_array($item['children'])) {
            $entry['children'] = processMenuItems($item['children']);
        }

        $result[] = $entry;
    }

    return $result;
}

// ----------------------------
// Save menu items
// ----------------------------
$menus[$menu]['items'] = processMenuItems($items);

// ----------------------------
// Save JSON
// ----------------------------
if (!save_menus($menus)) {
    redirect_with_toast('menu-edit', 'error', 'Failed to save menu.', $menu ? ['menu' => $menu] : [] );
}

// ----------------------------
// Success redirect
// ----------------------------
redirect_with_toast(
    'menu-edit',
    'success',
    "Menu \"{$menus[$menu]['label']}\" saved successfully.",
    ['menu' => $menu]
);
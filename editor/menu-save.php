<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/menus.php';

require_login();

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
    $redirectMenu = '';
    redirect_with_toast('menu-edit.php' . $redirectMenu, 'error', 'Menu name is required.');
}

// Normalize menu key
$menu = strtolower($menu);
$menu = preg_replace('/[\s_]+/', '-', $menu);
$menu = preg_replace('/[^a-z0-9\-]/', '', $menu); // allow letters, numbers, dash
$menu = preg_replace('/-+/', '-', $menu);
$menu = trim($menu, '-');


if ($menu === '') {
    $redirectMenu = '';
    redirect_with_toast('menu-edit.php' . $redirectMenu, 'error', 'Invalid menu name.');
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

        // Type-specific link
        if ($type === 'page') {
            $entry['slug'] = $item['slug'] ?? '';
        } elseif ($type === 'url') {
            $entry['url'] = $item['url'] ?? '';
        }

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
    $redirectMenu = $menu ? '?menu=' . urlencode($menu) : '';
    redirect_with_toast('menu-edit.php' . $redirectMenu, 'error', 'Failed to save menu.');
}

// ----------------------------
// Success redirect
// ----------------------------
redirect_with_toast(
    'menu-edit.php?menu=' . urlencode($menu),
    'success',
    "Menu \"{$menus[$menu]['label']}\" saved successfully."
);
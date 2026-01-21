<?php
declare(strict_types=1);

// ----------------------------
// Get menu slug from query
// ----------------------------
$menuSlug = trim($_GET['menu'] ?? '');
if ($menuSlug === '') {
    redirect_with_toast('menu-edit', 'error', 'Missing menu name.');
}

// ----------------------------
// Load menu to get label for feedback
// ----------------------------
$menu = get_menu($menuSlug);
if (!$menu['label']) {
    redirect_with_toast('menu-edit', 'error', 'Menu not found.');
}

// ----------------------------
// Delete menu
// ----------------------------
if (!delete_menu($menuSlug)) {
    redirect_with_toast('menu-edit', 'error', "Failed to delete menu \"{$menu['label']}\".");
}

// ----------------------------
// Success
// ----------------------------
redirect_with_toast(
    'menu-edit',
    'success',
    "Menu \"{$menu['label']}\" removed successfully."
);

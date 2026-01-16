<?php

// ----------------------------
// Get menu name from query
// ----------------------------
$menu = trim($_GET['menu'] ?? '');
if ($menu === '') {
    redirect_with_toast('menu-edit', 'error', 'Missing menu name.');
}

// ----------------------------
// Load existing menus
// ----------------------------
$menus = load_menus();

if (!isset($menus[$menu])) {
    redirect_with_toast('menu-edit', 'error', 'Menu not found.');
}

// ----------------------------
// Remove menu
// ----------------------------
unset($menus[$menu]);

if (!save_menus($menus)) {
    redirect_with_toast('menu-edit', 'error', 'Failed to remove menu.');
}

// ----------------------------
// Success
// ----------------------------
invalidate_cache();
redirect_with_toast('menu-edit', 'success', "Menu \"$menu\" removed successfully.");

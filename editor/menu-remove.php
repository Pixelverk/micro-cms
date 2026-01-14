<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/menus.php';

require_login();

// ----------------------------
// Get menu name from query
// ----------------------------
$menu = trim($_GET['menu'] ?? '');
if ($menu === '') {
    redirect_with_toast('menu-edit.php', 'error', 'Missing menu name.');
}

// ----------------------------
// Load existing menus
// ----------------------------
$menus = load_menus();

if (!isset($menus[$menu])) {
    redirect_with_toast('menu-edit.php', 'error', 'Menu not found.');
}

// ----------------------------
// Remove menu
// ----------------------------
unset($menus[$menu]);

if (!save_menus($menus)) {
    redirect_with_toast('menu-edit.php', 'error', 'Failed to remove menu.');
}

// ----------------------------
// Success
// ----------------------------
redirect_with_toast('menu-edit.php', 'success', "Menu \"$menu\" removed successfully.");
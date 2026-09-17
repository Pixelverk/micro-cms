<?php
declare(strict_types=1);

// ----------------------------
// POST only (destructive action)
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// ----------------------------
// Get menu slug from the form
// ----------------------------
$menuSlug = trim($_POST['menu'] ?? '');
if ($menuSlug === '') {
    redirect_with_toast('menu/edit', 'error', 'Missing menu name.');
}

// ----------------------------
// Load menu to get label for feedback
// ----------------------------
$menu = get_menu($menuSlug);
if (!$menu['label']) {
    redirect_with_toast('menu/edit', 'error', 'Menu not found.');
}

// ----------------------------
// Delete menu
// ----------------------------
if (!delete_menu($menuSlug)) {
    redirect_with_toast('menu/edit', 'error', "Failed to delete menu \"{$menu['label']}\".");
}

// ----------------------------
// Success
// ----------------------------
log_activity('menu.deleted', 'menu', null, (string) $menu['label'], []);

redirect_with_toast(
    'menu/edit',
    'success',
    "Menu \"{$menu['label']}\" removed successfully."
);

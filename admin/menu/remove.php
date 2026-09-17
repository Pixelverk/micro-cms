<?php
declare(strict_types=1);

// ----------------------------
// POST only (destructive action)
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(admin_trans('error_method'));
}

// ----------------------------
// Get menu slug from the form
// ----------------------------
$menuSlug = trim($_POST['menu'] ?? '');
if ($menuSlug === '') {
    redirect_with_toast('menu/edit', 'error', admin_trans('menu_error_missing'));
}

// ----------------------------
// Load menu to get label for feedback
// ----------------------------
$menu = get_menu($menuSlug);
if (!$menu['label']) {
    redirect_with_toast('menu/edit', 'error', admin_trans('menu_error_not_found'));
}

// ----------------------------
// Delete menu
// ----------------------------
if (!delete_menu($menuSlug)) {
    redirect_with_toast('menu/edit', 'error', admin_trans('menu_error_delete', ['name' => $menu['label']]));
}

// ----------------------------
// Success
// ----------------------------
log_activity('menu.deleted', 'menu', null, (string) $menu['label'], []);

redirect_with_toast(
    'menu/edit',
    'success',
    admin_trans('menu_removed', ['name' => $menu['label']])
);

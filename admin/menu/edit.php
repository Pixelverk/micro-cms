<?php

$pageTitle = admin_trans('nav_menus');
$username = current_username();

// ----------------------------
// Load all menus
$menus = load_menus();
$theme = theme_config();
$locations = $theme['menu_locations'] ?? [];
$assignments = get_setting('menu_locations', []);
$location = $_GET['location'] ?? array_key_first($locations);
if (!array_key_exists($location, $locations)) {
    $location = array_key_first($locations);
}
$assignedMenu = is_array($assignments) ? ($assignments[$location] ?? '') : '';
$menuKey = $_GET['menu'] ?? $assignedMenu;
$currentMenu = $menus[$menuKey] ?? ['label' => '', 'items' => []];

// Load pages for left panel
$pages = list_content('page');

// Render
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('common_hello', ['name' => $username])) ?></h2>
        <p><?= e(admin_trans('menu_edit_title')) ?></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="menu-save" style="margin-top:1rem;"><?= e(admin_trans('menu_save')) ?></button>
    </div>
</div>

<!-- Select or create menu -->
<form method="get" style="margin-bottom:1rem;">
    <label>
        <?= e(admin_trans('menu_location')) ?>:
        <select name="location" onchange="this.form.submit()">
            <?php foreach ($locations as $locationKey => $locationLabel): ?>
                <option value="<?= e($locationKey) ?>" <?= $locationKey === $location ? 'selected' : '' ?>><?= e($locationLabel) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <?= e(admin_trans('menu_assigned')) ?>:
        <select name="menu" onchange="this.form.submit()">
            <option value=""><?= e(admin_trans('menu_new')) ?></option>
            <?php foreach ($menus as $key => $m): ?>
                <option value="<?= e($key) ?>" <?= $key === $menuKey ? 'selected' : '' ?>>
                    <?= e($m['label'] ?: $key) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<form id="menu-save" method="post" action="<?= url('admin/menu/save') ?>">
    <?= csrf_field() ?>

    <input name="menu" id="menu-key" value="<?= e($menuKey) ?>">
    <input type="hidden" name="location" value="<?= e($location) ?>">

    <label>
        <?= e(admin_trans('menu_label')) ?>:
        <input type="text" name="label" id="menu-label" value="<?= e($currentMenu['label']) ?>">
    </label>

    <div style="display:flex; gap:2rem; margin-top:1rem;">

        <!-- Left panel: add items -->
        <div style="flex:1; border:1px solid #ccc; padding:1rem;">
            <h3><?= e(admin_trans('menu_add_items')) ?></h3>
            
            <div>
                <label><?= e(admin_trans('menu_from_pages')) ?></label>
                <select id="new-item-page">
                    <option value=""><?= e(admin_trans('menu_select_page')) ?></option>
                    <?php foreach ($pages as $p): ?>
                        <option value="<?= e($p['slug']) ?>"><?= e($p['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="add-page-item"><?= e(admin_trans('common_add')) ?></button>
            </div>

            <div style="margin-top:1rem;">
                <label><?= e(admin_trans('menu_custom_url')) ?></label>
                <input type="text" id="new-item-url" placeholder="https://example.com">
                <input type="text" id="new-item-label" placeholder="<?= e(admin_trans('common_label')) ?>">
                <select id="new-item-target">
                    <option value="_self"><?= e(admin_trans('menu_target_same')) ?></option>
                    <option value="_blank"><?= e(admin_trans('menu_target_new')) ?></option>
                </select>
                <button type="button" id="add-url-item"><?= e(admin_trans('common_add')) ?></button>
            </div>
        </div>

        <!-- Right panel: menu items editor -->
        <div style="flex:2;">
            <h3><?= e(admin_trans('menu_items')) ?></h3>
            <div id="menu-items-container"></div>
        </div>
    </div>
</form>

<?php if ($menuKey): ?>
    <form method="post"
        action="<?= url('admin/menu/remove') ?>"
        class="js-confirm-form"
        data-confirm="<?= e(admin_trans('menu_delete_confirm', ['name' => $menuKey])) ?>"
        data-confirm-title="<?= e(admin_trans('menu_delete')) ?>"
        style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="menu" value="<?= e($menuKey) ?>">
        <button type="submit" class="btn-delete btn-small"><?= e(admin_trans('common_delete')) ?></button>
    </form>
<?php endif; ?>

<!-- Menu item template -->
<?php include CMS_PATH . '/admin/partials/menu-editor-templates.php'; ?>
<script type="module" src="<?= url('admin/assets/menu-editor.js') ?>"></script>

<script>
    window.initialMenuItems = <?= json_encode($currentMenu['items']) ?>;

    // Auto-generate menuKey from label if new
    const menuLabelInput = document.getElementById('menu-label');
    const menuKeyInput   = document.getElementById('menu-key');

    menuLabelInput.addEventListener('input', () => {
        // Only auto-generate if creating new menu
        if (!<?= json_encode((bool)$menuKey) ?>) {
            menuKeyInput.value = menuLabelInput.value.toLowerCase()
                .replace(/[\s_]+/g, '-')
                .replace(/[^a-z0-9\-]/g, '')
                .replace(/-+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
    });

</script>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('nav_menus')) ?></h3>
<p><?= e(admin_trans('menu_help')) ?></p>
<ul>
    <li><?= e(admin_trans('menu_help_locations')) ?></li>
    <li><?= e(admin_trans('menu_help_nesting')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'editor', 'section' => 'getting-around'];

include CMS_PATH . '/admin/partials/layout.php';
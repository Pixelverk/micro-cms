<?php

$pageTitle = 'Edit Menu';
$username = $_SESSION['user_id'] ?? 'User';

// ----------------------------
// Load all menus
$menus = load_menus();
$menuKey = $_GET['menu'] ?? '';
$currentMenu = $menus[$menuKey] ?? ['label' => '', 'items' => []];

// Load pages for left panel
$pages = list_content('page');

// Render
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p>Edit your navigation menu</p>
    </div>
    <div class="page-actions">
        <button type="submit" form="menu-save" style="margin-top:1rem;">Save Menu</button>
    </div>
</div>

<!-- Select or create menu -->
<form method="get" style="margin-bottom:1rem;">
    <label>
        Select Menu:
        <select name="menu" onchange="this.form.submit()">
            <option value="">-- New Menu --</option>
            <?php foreach ($menus as $key => $m): ?>
                <option value="<?= e($key) ?>" <?= $key === $menuKey ? 'selected' : '' ?>>
                    <?= e($m['label'] ?: $key) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<form id="menu-save" method="post" action="<?= url('admin/menu-save') ?>">

    <input name="menu" id="menu-key" value="<?= e($menuKey) ?>">

    <label>
        Menu Label:
        <input type="text" name="label" id="menu-label" value="<?= e($currentMenu['label']) ?>">
    </label>

    <div style="display:flex; gap:2rem; margin-top:1rem;">

        <!-- Left panel: add items -->
        <div style="flex:1; border:1px solid #ccc; padding:1rem;">
            <h3>Add Items</h3>
            
            <div>
                <label>From Pages:</label>
                <select id="new-item-page">
                    <option value="">-- Select page --</option>
                    <?php foreach ($pages as $p): ?>
                        <option value="<?= e($p['slug']) ?>"><?= e($p['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="add-page-item">Add</button>
            </div>

            <div style="margin-top:1rem;">
                <label>Custom URL:</label>
                <input type="text" id="new-item-url" placeholder="https://example.com">
                <input type="text" id="new-item-label" placeholder="Label">
                <select id="new-item-target">
                    <option value="_self">Same tab</option>
                    <option value="_blank">New tab</option>
                </select>
                <button type="button" id="add-url-item">Add</button>
            </div>
        </div>

        <!-- Right panel: menu items editor -->
        <div style="flex:2;">
            <h3>Menu Items</h3>
            <div id="menu-items-container"></div>
        </div>
    </div>

    <?php if ($menuKey): ?>
        <button type="button" id="delete-menu-btn" style="background:#e74c3c; color:white;">Delete Menu</button>
    <?php endif; ?>
</form>

<!-- Menu item template -->
<?php include __DIR__ . '/partials/menu-editor-templates.php'; ?>
<script type="module" src="<?= url('admin/assets/menu-editor.js') ?>"></script>

<script>
    window.initialMenuItems = <?= json_encode($currentMenu['items']) ?>;

    // Auto-generate menuKey from label if new
    const menuLabelInput = document.getElementById('menu-label');
    const menuKeyInput   = document.getElementById('menu-key');

    menuLabelInput.addEventListener('input', () => {
        menuKeyInput.value = menuLabelInput.value.toLowerCase()
            .replace(/[\s_]+/g, '-')
            .replace(/[^a-z0-9\-]/g, '')
            .replace(/-+/g, '-')
            .replace(/^-+|-+$/g, '');
    });

    // Delete menu button
    const deleteBtn = document.getElementById('delete-menu-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', () => {
            const menu = menuKeyInput.value;
            if (!menu) return;
            if (!confirm(`Delete menu "${menu}"?\nThis cannot be undone.`)) return;
            window.location.href = "<?= url('admin/menu-remove') ?>?menu=" + encodeURIComponent(menu);
        });
    }
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/partials/layout.php';
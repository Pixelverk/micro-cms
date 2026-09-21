<?php

$pageTitle = admin_trans('nav_menus');

// ----------------------------
// Load all menus
$menus = load_menus();
$theme = theme_config();
$locations = $theme['menu_locations'] ?? [];
$assignments = get_setting('menu_locations', []);
if (!is_array($assignments)) {
    $assignments = [];
}

// "New menu" clears the selection; the menu is created on first save.
$isNew = isset($_GET['new']);
$menuKey = $isNew ? '' : (string) ($_GET['menu'] ?? '');
if ($menuKey !== '' && !isset($menus[$menuKey])) {
    $menuKey = '';
}

$currentMenu = $menus[$menuKey] ?? ['label' => '', 'items' => []];

// Which locations this menu currently fills.
$menuLocations = [];
foreach ($locations as $locationKey => $locationLabel) {
    if (($assignments[$locationKey] ?? '') === $menuKey && $menuKey !== '') {
        $menuLocations[] = $locationKey;
    }
}

// ----------------------------
// What an item can point at
// ----------------------------
// Every content type the theme declares, then the archives. Each option carries
// what the editor needs to build the item: the kind, the id the link resolves
// with, the slug as its fallback, and the path to display.
$pickerGroups = [];

foreach ($theme['content_types'] ?? [] as $typeKey => $typeConfig) {
    $rows = list_content((string) $typeKey);

    if (!$rows) {
        continue;
    }

    $paths   = content_path_rows((string) $typeKey);
    $options = [];

    foreach ($rows as $row) {
        // list_content() returns publishing columns only, so the type the URL
        // prefix comes from has to be filled in here.
        $row['type'] = (string) $typeKey;

        $options[] = [
            'label' => (string) $row['title'],
            'path'  => content_url($row, $paths),
            'type'  => (string) $typeKey,
            'id'    => (int) $row['id'],
            'slug'  => (string) $row['slug'],
        ];
    }

    $pickerGroups[] = [
        'key'     => (string) $typeKey,
        'label'   => (string) ($typeConfig['label'] ?? ucfirst((string) $typeKey)),
        'options' => $options,
    ];
}

foreach (theme_taxonomies() as $taxonomyName => $taxonomyConfig) {
    $stmt = db()->prepare("SELECT name, slug FROM taxonomy WHERE taxonomy_type = ? ORDER BY name COLLATE NOCASE ASC");
    $stmt->execute([$taxonomyName]);
    $options = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $term) {
        $options[] = [
            'label'    => (string) $term['name'],
            'path'     => taxonomy_url($taxonomyName, (string) $term['slug']),
            'type'     => 'taxonomy',
            'taxonomy' => $taxonomyName,
            'id'       => 0,
            'slug'     => (string) $term['slug'],
        ];
    }

    if ($options) {
        $pickerGroups[] = ['key' => 'taxonomy:' . $taxonomyName, 'label' => taxonomy_label($taxonomyName, true), 'options' => $options];
    }
}

// The second select is filled from this, so a type can be picked without a
// round trip to the server.
$pickerOptions = [];

foreach ($pickerGroups as $group) {
    $pickerOptions[$group['key']] = $group['options'];
}

// The editor shows hidden items too — dimmed — so a parked branch stays visible,
// and an item whose link no longer resolves is flagged.
$editorContext = ['rows' => [], 'terms' => []];
$editorItems   = menu_items_resolve($currentMenu['items'] ?? [], $editorContext, true);

// Render
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('nav_menus')) ?></h2>
        <p><?= e(admin_trans('menu_edit_title')) ?></p>
    </div>
    <div class="page-actions">
        <?php if ($menuKey !== ''): ?>
            <form method="post"
                action="<?= url('admin/menu/remove') ?>"
                class="js-confirm-form"
                data-confirm="<?= e(admin_trans('menu_delete_confirm', ['name' => $menuKey])) ?>"
                data-confirm-title="<?= e(admin_trans('menu_delete')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="menu" value="<?= e($menuKey) ?>">
                <button type="submit" class="btn-text btn-text-danger"><?= e(admin_trans('menu_delete')) ?></button>
            </form>
        <?php endif; ?>

        <button type="submit" form="menu-save"><?= e(admin_trans('menu_save')) ?></button>
    </div>
</div>

<form id="menu-save" method="post" action="<?= url('admin/menu/save') ?>">
    <?= csrf_field() ?>

    <!-- Derived from the label for a new menu, fixed for an existing one. -->
    <input type="hidden" name="menu" id="menu-key" value="<?= e($menuKey) ?>">

    <div class="menu-editor">

        <!-- Sidebar: which menu, what it is called, where it shows, what to add -->
        <aside class="menu-sidebar">

            <section class="menu-panel">
                <h3><?= e(admin_trans('menu_select')) ?></h3>

                <select id="menu-picker" class="field-input">
                    <option value=""><?= e(admin_trans('menu_new')) ?></option>
                    <?php foreach ($menus as $key => $menu): ?>
                        <option value="<?= e($key) ?>" <?= $key === $menuKey ? 'selected' : '' ?>>
                            <?= e($menu['label'] ?: $key) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </section>

            <section class="menu-panel">
                <div class="field">
                    <label class="field-label" for="menu-label"><?= e(admin_trans('menu_label')) ?></label>
                    <input type="text" class="field-input" name="label" id="menu-label" value="<?= e($currentMenu['label']) ?>" placeholder="<?= e(admin_trans('menu_label_help')) ?>">
                    <small><?= e(admin_trans('menu_label_help')) ?></small>
                </div>
            </section>

            <?php if ($locations): ?>
                <section class="menu-panel">
                    <h3><?= e(admin_trans('menu_location')) ?></h3>

                    <div class="menu-locations">
                        <?php foreach ($locations as $locationKey => $locationLabel): ?>
                            <label class="menu-location">
                                <input type="checkbox" name="locations[]" value="<?= e($locationKey) ?>"
                                    <?= in_array($locationKey, $menuLocations, true) ? 'checked' : '' ?>>
                                <?= e($locationLabel) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <p class="menu-panel-hint"><?= e(admin_trans('menu_locations_help')) ?></p>
                </section>
            <?php endif; ?>

            <section class="menu-panel">
                <h3><?= e(admin_trans('menu_add_items')) ?></h3>

                <div class="field menu-add-block">
                    <span class="field-label"><?= e(admin_trans('menu_from_content')) ?></span>
                    <select id="new-item-kind" class="field-input">
                        <option value=""><?= e(admin_trans('menu_select_type')) ?></option>
                        <?php foreach ($pickerGroups as $group): ?>
                            <option value="<?= e($group['key']) ?>">
                                <?= e($group['label']) ?> (<?= count($group['options']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="new-item-link" class="field-input" aria-label="<?= e(admin_trans('menu_select_content')) ?>" disabled>
                        <option value=""><?= e(admin_trans('menu_select_content')) ?></option>
                    </select>
                    <button type="button" id="add-link-item" class="btn-secondary" disabled><?= e(admin_trans('common_add')) ?></button>
                </div>

                <div class="field menu-add-block">
                    <label class="field-label" for="new-item-url"><?= e(admin_trans('menu_custom_url')) ?></label>
                    <input type="text" id="new-item-url" class="field-input" placeholder="https://example.com">
                    <input type="text" id="new-item-label" class="field-input" placeholder="<?= e(admin_trans('common_label')) ?>">
                    <select id="new-item-target" class="field-input">
                        <option value="_self"><?= e(admin_trans('menu_target_same')) ?></option>
                        <option value="_blank"><?= e(admin_trans('menu_target_new')) ?></option>
                    </select>
                    <button type="button" id="add-url-item" class="btn-secondary"><?= e(admin_trans('common_add')) ?></button>
                </div>
            </section>
        </aside>

        <!-- The menu itself -->
        <section class="menu-items-panel">
            <h3><?= e(admin_trans('menu_items')) ?></h3>
            <div id="menu-items-container"></div>
        </section>
    </div>
</form>

<!-- Menu item template -->
<?php include CMS_PATH . '/admin/partials/menu-editor-templates.php'; ?>

<?php
// Editor libraries are vendored locally (no CDN, no build step) and must run
// before the editor module below. Sortable powers drag-and-drop ordering of
// menu items, including into and out of a nested position.
$pageScripts[] = ['src' => 'admin/assets/vendor/sortable/Sortable.min.js'];
?>
<script type="module" src="<?= url('admin/assets/menu-editor.js') ?>"></script>

<script>
    window.initialMenuItems = <?= json_encode($editorItems) ?>;
    window.menuLinkOptions  = <?= json_encode($pickerOptions) ?>;

    // Selecting a menu navigates to it; the panel is part of the save form, so
    // the choice is made with a plain GET rather than a nested form.
    document.getElementById('menu-picker').addEventListener('change', (event) => {
        const slug = event.target.value;
        window.location = slug === ''
            ? <?= json_encode(url('admin/menu/edit') . '?new=1') ?>
            : <?= json_encode(url('admin/menu/edit')) ?> + '?menu=' + encodeURIComponent(slug);
    });

    // Auto-generate the menu key from the label while it is still new.
    const menuLabelInput = document.getElementById('menu-label');
    const menuKeyInput   = document.getElementById('menu-key');

    menuLabelInput.addEventListener('input', () => {
        if (!<?= json_encode((bool) $menuKey) ?>) {
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

<?php
// core/admin/content-list.php

$pageTitle = 'Content';
$username  = $_SESSION['user_id'] ?? 'User';

// ----------------------------
// Determine content type
// ----------------------------
$theme = theme_config();
$settings = load_settings();
$contentTypes = $theme['content_types'] ?? [];

$type = $_GET['type'] ?? array_key_first($contentTypes);

$ctConfig = $contentTypes[$type];
$typeLabel  = $ctConfig['label'] ?? ucfirst($type);

$prefix = $settings['content_prefixes'][$type] ?? $ctConfig['url_prefix'] ?? '';

// ----------------------------
// Load content items
// ----------------------------
$items = list_content($type); // new generalized function

// ----------------------------
// Render content list
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p>Manage your <?= e($typeLabel) ?>s below.</p>
    </div>

    <div class="page-actions" style="display:flex; gap:0.75rem; align-items:center;">

        <label style="display:flex; align-items:center; gap:0.5rem; margin:0;">
            <span>Type:</span>
            <select id="content-type-select">
                <?php foreach ($contentTypes as $key => $config): ?>
                    <option value="<?= e($key) ?>" <?= $key === $type ? 'selected' : '' ?>>
                        <?= e($config['label'] ?? ucfirst($key)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <a href="<?= url("admin/content-edit") ?>?type=<?= urlencode($type) ?>" class="btn-primary">+ Add New <?= e($typeLabel) ?></a>

    </div>
    
</div>

<?php if (empty($items)): ?>
    <p>No <?= e($typeLabel) ?>s found. Create your first one.</p>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Slug</th>
                <th style="width: 180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): $url = '/' . ($prefix ? $prefix . '/' : '') . $item['slug']; ?>
                <tr>
                    <td>
                        <a style="text-decoration:none; color:inherit;" 
                           href="<?= url($item['slug'] == e(get_setting('homepage_slug')) ? '' : $url) ?>" 
                           target="_blank">
                            <?= e($item['title']) ?>
                        </a>
                    </td>
                    <td>
                        <code><?= e($item['slug']) ?></code>
                    </td>
                    <td class="actions">
                        <a href="<?= url("admin/content-edit") ?>?type=<?= $type ?>&slug=<?= urlencode($item['slug']) ?>"
                           class="btn-small">
                            Edit
                        </a>

                        <button
                            type="button"
                            class="btn-delete btn-small delete-content-btn"
                            data-slug="<?= e($item['slug']) ?>"
                        >
                            Delete
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
document.querySelectorAll('.delete-content-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const slug = btn.dataset.slug;
        if (confirm(`Are you sure you want to delete the ${"<?= e($typeLabel) ?>"} "${slug}"?\n\nThis action cannot be undone.`)) {
            window.location.href = "<?= url('admin/content-remove') ?>?type=<?= urlencode($type) ?>&slug=" + encodeURIComponent(slug);
        }
    });
});

const typeSelect = document.getElementById('content-type-select');

if (typeSelect) {
    typeSelect.addEventListener('change', () => {
        const type = typeSelect.value;
        const url = new URL(window.location.href);
        url.searchParams.set('type', type);
        window.location.href = url.toString();
    });
}

</script>

<?php
$content = ob_get_clean();

// ----------------------------
// Content help panel
// ----------------------------
ob_start();
?>
<h3><?= e($typeLabel) ?> list</h3>
<p>This screen lists all active <?= e($typeLabel) ?>s of type "<strong><?= e($type) ?></strong>".</p>
<p>You can add, edit, or delete <?= e($typeLabel) ?>s using the buttons above.</p>
<p>Clicking the title will open the content on the front-end.</p>
<?php
$pageHelp = ob_get_clean();

include __DIR__ . '/partials/layout.php';
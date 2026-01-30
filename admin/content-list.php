<?php
// admin/content-list.php

$pageTitle = 'Content';
$username  = $_SESSION['user_id'] ?? 'User';

// ----------------------------
// Determine content type
// ----------------------------
$theme = theme_config();
$settings = load_settings();
$contentTypes = $theme['content_types'] ?? [];

$type = $_GET['type'] ?? array_key_first($contentTypes);

$ctConfig  = $contentTypes[$type];
$typeLabel = $ctConfig['label'] ?? ucfirst($type);

$prefix = $settings['content_prefixes'][$type] ?? $ctConfig['url_prefix'] ?? '';
$prefix = rtrim($prefix, '/'); // <- remove trailing slash

$homepageSlug = $settings['homepage_slug'];

// ----------------------------
// Load content items
// ----------------------------
$items = list_content($type);

usort($items, function($a, $b) {
    return ($a['parent_id'] ?? 0) <=> ($b['parent_id'] ?? 0);
});

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e($typeLabel) ?>s</h2>
    </div>

    <div class="page-actions flex gap-md items-center">
        <label class="flex items-center gap-sm mb-0">
            <span>Type:</span>
            <select id="content-type-select">
                <?php foreach ($contentTypes as $key => $config): ?>
                    <option value="<?= e($key) ?>" <?= $key === $type ? 'selected' : '' ?>>
                        <?= e($config['label'] ?? ucfirst($key)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <a href="<?= url('admin/content-edit') ?>?type=<?= urlencode($type) ?>"
           class="btn-primary">
            + Add New <?= e($typeLabel) ?>
        </a>
    </div>
</div>

<?php if (empty($items)): ?>
    <p>No <?= e($typeLabel) ?>s found.</p>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Slug</th>
                <th>Status</th>
                <th>Published</th>
                <th>Updated</th>
                <th style="width:180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item):
            $fullSlug = build_full_slug($item, $items);
            $url = '/' . ($prefix ? $prefix . '/' : '') . $fullSlug;
            $isHomepage = $item['slug'] === $homepageSlug;
        ?>
            <tr>
                <td>
                    <a href="<?= url($isHomepage ? '' : $url) ?>"
                    target="_blank"
                    style="text-decoration:none; color:inherit;">
                        <?= e($item['title']) ?>
                        <?php if ($isHomepage): ?>
                            <span class="badge badge-home">home</span>
                        <?php endif; ?>
                    </a>
                </td>

                <td><code><?= e($fullSlug) ?></code></td>

                <td>
                    <span class="status status-<?= e($item['status']) ?>">
                        <?= e(ucfirst($item['status'])) ?>
                    </span>
                </td>

                <td>
                    <?= $item['published_at']
                        ? date('Y-m-d', (int)$item['published_at'])
                        : '—' ?>
                </td>

                <td>
                    <?= date('Y-m-d', (int)$item['updated_at']) ?>
                </td>

                <td class="actions">
                    <a href="<?= url('admin/content-edit') ?>?type=<?= urlencode($type) ?>&id=<?= (int)$item['id'] ?>"
                        class="btn-small">
                        Edit
                    </a>

                    <a href="<?= url('admin/content-remove') ?>?id=<?= (int)$item['id'] ?>"
                        class="js-confirm btn-delete btn-small"
                        data-confirm="Do you want to remove this item: <?= e($item['title'])?>"
                        data-confirm-title="Delete content">
                        Delete
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
const typeSelect = document.getElementById('content-type-select');
if (typeSelect) {
    typeSelect.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('type', typeSelect.value);
        window.location.href = url.toString();
    });
}
</script>

<?php
$content = ob_get_clean();

// ----------------------------
// Help panel
// ----------------------------
ob_start();
?>
<h3><?= e($typeLabel) ?> list</h3>
<p>This screen shows all <?= e($typeLabel) ?> content.</p>
<ul>
    <li><strong>Status</strong> shows draft vs published</li>
    <li><strong>Published</strong> is empty until scheduled or published</li>
    <li><strong>Updated</strong> reflects last edit</li>
</ul>
<?php
$pageHelp = ob_get_clean();

include __DIR__ . '/partials/layout.php';
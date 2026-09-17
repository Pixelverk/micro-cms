<?php
// admin/content/index.php

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
            <span><?= e(admin_trans('type')) ?>:</span>
            <select id="content-type-select">
                <?php foreach ($contentTypes as $key => $config): ?>
                    <option value="<?= e($key) ?>" <?= $key === $type ? 'selected' : '' ?>>
                        <?= e($config['label'] ?? ucfirst($key)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <a href="<?= url('admin/content/edit') ?>?type=<?= urlencode($type) ?>"
           class="btn-primary">
            + <?= e(admin_trans('add')) ?> <?= e($typeLabel) ?>
        </a>
    </div>
</div>

<?php if (empty($items)): ?>
    <p><?= e(admin_trans('no_content', ['type' => $typeLabel])) ?></p>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th><?= e(admin_trans('content_title')) ?></th>
                <th><?= e(admin_trans('slug')) ?></th>
                <th><?= e(admin_trans('status')) ?></th>
                <th><?= e(admin_trans('published')) ?></th>
                <th><?= e(admin_trans('scheduled')) ?></th>
                <th><?= e(admin_trans('updated')) ?></th>
                <th style="width:180px;"><?= e(admin_trans('actions')) ?></th>
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
                            <span class="badge badge-home"><?= e(admin_trans('home')) ?></span>
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
                    <?= format_local_datetime($item['published_at'], 'Y-m-d') ?>
                </td>

                <td>
                    <?= format_local_datetime($item['scheduled_at'], 'Y-m-d H:i') ?>
                </td>

                <td>
                    <?= format_local_datetime($item['updated_at'], 'Y-m-d') ?>
                </td>

                <td class="actions">
                    <a href="<?= url('admin/content/edit') ?>?type=<?= urlencode($type) ?>&id=<?= (int)$item['id'] ?>"
                        class="btn-small">
                        <?= e(admin_trans('edit')) ?>
                    </a>

                    <a href="<?= url('admin/content/remove') ?>?id=<?= (int)$item['id'] ?>"
                        class="js-confirm btn-delete btn-small"
                        data-confirm="<?= e(admin_trans('delete_content_confirm', ['name' => $item['title']])) ?>"
                        data-confirm-title="<?= e(admin_trans('delete_content')) ?>">
                        <?= e(admin_trans('delete')) ?>
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
<p><?= e(admin_trans('content_list_help', ['type' => $typeLabel])) ?></p>
<ul>
    <li><?= e(admin_trans('status_help')) ?></li>
    <li><?= e(admin_trans('published_help')) ?></li>
    <li><?= e(admin_trans('updated_help')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';
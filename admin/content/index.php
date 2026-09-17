<?php
// admin/content/index.php

$pageTitle = admin_trans('nav_content');

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
// Filters
// ----------------------------
// Tags are offered as a bulk action target.
$tagStmt = db()->prepare("
    SELECT id, name
    FROM taxonomy
    WHERE taxonomy_type = 'tag'
    AND content_type = ?
    ORDER BY name
");
$tagStmt->execute([$type]);
$tags = $tagStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Authors work on their own drafts; other roles see everything.
$visibleToUser = function (array $list): array {
    if (admin_can('content.edit.any')) {
        return $list;
    }

    $mine = function_exists('current_user_id') ? current_user_id() : null;

    return array_values(array_filter($list, function ($item) use ($mine) {
        $owner = $item['created_by'] ?? null;

        // Unattributed content stays visible so it is not stranded.
        return $owner === null || (int) $owner === (int) $mine;
    }));
};

$statusFilter = (string) ($_GET['status'] ?? '');
if ($statusFilter !== '' && $statusFilter !== 'trash' && !in_array($statusFilter, content_statuses(), true)) {
    $statusFilter = '';
}

$isTrashView  = $statusFilter === 'trash';
$searchFilter = trim((string) ($_GET['q'] ?? ''));

// The Trash tab is a different query: trashed rows instead of live ones.
$liveItems    = $visibleToUser(list_content_admin($type));
$trashedItems = $visibleToUser(list_content_admin($type, ['trashed' => true]));
$allItems     = $isTrashView ? $trashedItems : $liveItems;

// Counts are computed before filtering so every tab shows its own total.
$statusCounts = array_fill_keys(content_statuses(), 0);
foreach ($liveItems as $item) {
    $itemStatus = (string) ($item['status'] ?? 'draft');
    $statusCounts[$itemStatus] = ($statusCounts[$itemStatus] ?? 0) + 1;
}

$items = $allItems;

if ($statusFilter !== '' && !$isTrashView) {
    $items = array_values(array_filter($items, fn($item) => ($item['status'] ?? '') === $statusFilter));
}

if ($searchFilter !== '') {
    $needle = mb_strtolower($searchFilter);
    $items = array_values(array_filter($items, function ($item) use ($needle) {
        return str_contains(mb_strtolower((string) ($item['title'] ?? '')), $needle)
            || str_contains(mb_strtolower((string) ($item['slug'] ?? '')), $needle);
    }));
}

// Order parents before their children, then by title (the query already sorts
// by title, so this only groups the hierarchy).
usort($items, function ($a, $b) {
    return ($a['parent_id'] ?? 0) <=> ($b['parent_id'] ?? 0);
});

// URL that preserves the current filters while changing one of them.
$filterUrl = function (array $overrides = []) use ($type, $statusFilter, $searchFilter): string {
    $query = array_filter(array_merge([
        'type'   => $type,
        'status' => $statusFilter,
        'q'      => $searchFilter,
    ], $overrides), fn($value) => $value !== '' && $value !== null);

    return url('admin/content') . '?' . http_build_query($query);
};

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
            <span><?= e(admin_trans('common_type')) ?>:</span>
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
            + <?= e(admin_trans('common_add')) ?> <?= e($typeLabel) ?>
        </a>
    </div>
</div>

<?php
// ----------------------------
// Filter bar: status tabs + search
// ----------------------------
$total = count($liveItems);
$tabs = ['' => ['label' => admin_trans('content_status_all'), 'count' => $total]];

foreach (content_statuses() as $status) {
    $tabs[$status] = ['label' => content_status_label($status), 'count' => $statusCounts[$status] ?? 0];
}

$tabs['trash'] = ['label' => admin_trans('trash_title'), 'count' => count($trashedItems)];
?>
<div class="content-filters">
    <div class="status-tabs">
        <?php foreach ($tabs as $value => $tab): ?>
            <a href="<?= e($filterUrl(['status' => $value])) ?>"
               class="status-tab <?= $statusFilter === (string) $value ? 'active' : '' ?>">
                <?= e($tab['label']) ?>
                <span class="status-tab-count"><?= (int) $tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="content-search">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <?php if ($statusFilter !== ''): ?>
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
        <?php endif; ?>
        <input type="search" name="q" value="<?= e($searchFilter) ?>"
               placeholder="<?= e(admin_trans('content_search')) ?>" aria-label="<?= e(admin_trans('content_search')) ?>">
        <?php if ($searchFilter !== ''): ?>
            <a href="<?= e($filterUrl(['q' => ''])) ?>" class="btn-small btn-muted"><?= e(admin_trans('common_clear')) ?></a>
        <?php endif; ?>
    </form>
</div>

<?php if (admin_can('content.bulk') && !empty($items) && !$isTrashView): ?>
    <?php $availableTags = $tags; ?>
    <form id="bulk-form" method="post" action="<?= e(url('admin/content/bulk')) ?>" class="bulk-toolbar" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="<?= e($type) ?>">

        <span class="bulk-count"><strong id="bulk-count">0</strong> <?= e(admin_trans('bulk_selected')) ?></span>

        <label>
            <span class="visually-hidden"><?= e(admin_trans('bulk_action')) ?></span>
            <select name="bulk_action" id="bulk-action" class="field-input">
                <option value=""><?= e(admin_trans('bulk_action')) ?>…</option>
                <?php if (admin_can('content.publish')): ?>
                    <option value="publish"><?= e(admin_trans('bulk_publish')) ?></option>
                <?php endif; ?>
                <option value="draft"><?= e(admin_trans('status_draft')) ?></option>
                <option value="archive"><?= e(admin_trans('status_archived')) ?></option>
                <?php if (admin_can('content.delete')): ?>
                    <option value="delete"><?= e(admin_trans('trash_move')) ?></option>
                <?php endif; ?>
                <option value="clear_cache"><?= e(admin_trans('bulk_clear_cache')) ?></option>
                <option value="add_tag"><?= e(admin_trans('bulk_add_tag')) ?></option>
                <option value="remove_tag"><?= e(admin_trans('bulk_remove_tag')) ?></option>
            </select>
        </label>

        <label id="bulk-tag-wrap" hidden>
            <span class="visually-hidden"><?= e(admin_trans('bulk_tag')) ?></span>
            <select name="tag_id" id="bulk-tag" class="field-input">
                <option value=""><?= e(admin_trans('bulk_choose_tag')) ?>…</option>
                <?php foreach ($availableTags as $tagOption): ?>
                    <option value="<?= (int) $tagOption['id'] ?>"><?= e($tagOption['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <button type="submit" class="btn-small btn-primary"><?= e(admin_trans('bulk_apply')) ?></button>
        <button type="button" class="btn-small btn-muted" id="bulk-clear"><?= e(admin_trans('bulk_clear_selection')) ?></button>
    </form>
<?php endif; ?>

<?php if (empty($items)): ?>
    <p class="empty-state">
        <?php if ($isTrashView): ?>
            <?= e(admin_trans('trash_empty')) ?>
        <?php else: ?>
            <?= $statusFilter !== '' || $searchFilter !== ''
                ? 'No ' . e($typeLabel) . 's match these filters.'
                : e(admin_trans('content_empty', ['type' => $typeLabel])) ?>
        <?php endif; ?>
    </p>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <?php if (admin_can('content.bulk')): ?>
                    <th class="col-select">
                        <input type="checkbox" id="bulk-select-all" aria-label="<?= e(admin_trans('bulk_select_all')) ?>">
                    </th>
                <?php endif; ?>
                <th><?= e(admin_trans('content_title')) ?></th>
                <th><?= e(admin_trans('common_slug')) ?></th>
                <th><?= e(admin_trans('common_status')) ?></th>
                <th><?= e(admin_trans('status_published')) ?></th>
                <th><?= e(admin_trans('status_scheduled')) ?></th>
                <th><?= e(admin_trans('common_updated')) ?></th>
                <th class="col-actions"><?= e(admin_trans('common_actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item):
            $fullSlug = build_full_slug($item, $items);
            $url = '/' . ($prefix ? $prefix . '/' : '') . $fullSlug;
            $isHomepage = $item['slug'] === $homepageSlug;
            $publicUrl = url($isHomepage ? '' : $url);
            $itemStatus = (string) ($item['status'] ?? 'draft');
        ?>
            <tr data-content-id="<?= (int) $item['id'] ?>">
                <?php if (admin_can('content.bulk')): ?>
                    <td>
                        <input type="checkbox" class="bulk-row" name="ids[]" value="<?= (int) $item['id'] ?>"
                               form="bulk-form" aria-label="<?= e($item['title']) ?>">
                    </td>
                <?php endif; ?>
                <td>
                    <a href="<?= e($publicUrl) ?>"
                    target="_blank"
                    class="no-underline">
                        <?= e($item['title']) ?>
                        <?php if ($isHomepage): ?>
                            <span class="badge badge-home"><?= e(admin_trans('content_home_badge')) ?></span>
                        <?php endif; ?>
                    </a>
                </td>

                <td><code><?= e($fullSlug) ?></code></td>

                <td>
                    <span class="status status-<?= e($itemStatus) ?>">
                        <?= e(content_status_label($itemStatus)) ?>
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
                    <?php if ($isTrashView): ?>
                        <form method="post" action="<?= url('admin/content/restore') ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <input type="hidden" name="type" value="<?= e($type) ?>">
                            <button type="submit" class="btn-small btn-secondary">
                                <?= e(admin_trans('common_restore')) ?>
                            </button>
                        </form>

                        <?php if (admin_can('content.delete')): ?>
                        <form method="post"
                            action="<?= url('admin/content/remove') ?>"
                            data-confirm="<?= e(admin_trans('trash_purge_confirm', ['name' => $item['title']])) ?>"
                            data-confirm-title="<?= e(admin_trans('trash_delete_permanently')) ?>"
                            class="inline-form js-confirm-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <input type="hidden" name="type" value="<?= e($type) ?>">
                            <input type="hidden" name="purge" value="1">
                            <button type="submit" class="btn-delete btn-small">
                                <?= e(admin_trans('trash_delete_permanently')) ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php $canEditThis = can_edit_content($item); ?>

                        <a href="<?= e(preview_url($publicUrl)) ?>"
                            target="_blank"
                            class="btn-small btn-preview"
                            title="<?= e(admin_trans('content_preview_title')) ?>">
                            <?= e(admin_trans('common_preview')) ?>
                        </a>

                        <?php if ($canEditThis): ?>
                            <a href="<?= url('admin/content/edit') ?>?type=<?= urlencode($type) ?>&id=<?= (int)$item['id'] ?>"
                                class="btn-small">
                                <?= e(admin_trans('common_edit')) ?>
                            </a>
                        <?php endif; ?>

                        <?php if (admin_can('content.delete') && $canEditThis): ?>
                        <form method="post"
                            action="<?= url('admin/content/remove') ?>"
                            data-confirm="<?= e(admin_trans('trash_confirm', ['name' => $item['title']])) ?>"
                            data-confirm-title="<?= e(admin_trans('trash_move')) ?>"
                            class="inline-form js-confirm-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <input type="hidden" name="type" value="<?= e($type) ?>">
                            <button type="submit" class="btn-delete btn-small">
                                <?= e(admin_trans('trash_move')) ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
/* Bulk selection: the toolbar appears with the first checked row. */
(() => {
    const form = document.getElementById('bulk-form');

    if (!form) return;

    const boxes = Array.from(document.querySelectorAll('.bulk-row'));
    const countEl = document.getElementById('bulk-count');
    const selectAll = document.getElementById('bulk-select-all');
    const actionSelect = document.getElementById('bulk-action');
    const tagWrap = document.getElementById('bulk-tag-wrap');
    const tagSelect = document.getElementById('bulk-tag');
    const clearBtn = document.getElementById('bulk-clear');

    const selected = () => boxes.filter(box => box.checked);

    function sync() {
        const chosen = selected();

        form.hidden = chosen.length === 0;

        if (countEl) countEl.textContent = chosen.length;

        if (selectAll) {
            selectAll.checked = chosen.length > 0 && chosen.length === boxes.length;
            selectAll.indeterminate = chosen.length > 0 && chosen.length < boxes.length;
        }
    }

    boxes.forEach(box => box.addEventListener('change', sync));

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            boxes.forEach(box => { box.checked = selectAll.checked; });
            sync();
        });
    }

    if (actionSelect) {
        actionSelect.addEventListener('change', () => {
            const needsTag = actionSelect.value === 'add_tag' || actionSelect.value === 'remove_tag';

            if (tagWrap) tagWrap.hidden = !needsTag;
            if (needsTag && tagSelect) tagSelect.required = true;
            else if (tagSelect) tagSelect.required = false;
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            boxes.forEach(box => { box.checked = false; });
            if (selectAll) selectAll.checked = false;
            sync();
        });
    }

    // Destructive actions get one confirmation for the whole selection.
    form.addEventListener('submit', async event => {
        const action = actionSelect ? actionSelect.value : '';
        const total = selected().length;

        if (!action || total === 0) {
            event.preventDefault();
            return;
        }

        if (!['delete', 'archive'].includes(action)) return;

        event.preventDefault();

        const labelKey = action === 'delete' ? 'common_delete' : 'bulk_archive';

        const actionLabel = window.adminTranslations?.[labelKey] || action;
        const confirmMessage = (window.adminTranslations?.bulk_confirm_message || ':action: :count item(s)?')
            .replace(':action', actionLabel)
            .replace(':count', total);

        const ok = await confirmModal({
            title: window.adminTranslations?.common_confirm_action || 'Confirm',
            message: confirmMessage
        });

        if (ok) form.submit();
    });

    sync();
})();

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
<h3><?= e(admin_trans('content_list_title', ['type' => $typeLabel])) ?></h3>
<p><?= e(admin_trans('content_list_help', ['type' => $typeLabel])) ?></p>
<ul>
    <li><?= e(admin_trans('content_status_help')) ?></li>
    <li><?= e(admin_trans('content_published_help')) ?></li>
    <li><?= e(admin_trans('content_updated_help')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'editor', 'section' => 'creating-and-editing-content'];

include CMS_PATH . '/admin/partials/layout.php';
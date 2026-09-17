<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content version history
|--------------------------------------------------------------------------
|
| Lists the snapshots stored for one content item, shows a read-only view of
| any snapshot, and restores one. Restoring snapshots the current state first,
| so it is itself undoable.
|
*/

$type = (string) ($_GET['type'] ?? 'page');
$id   = (int) ($_GET['id'] ?? 0);

$theme        = theme_config();
$contentTypes = $theme['content_types'] ?? [];

if (!isset($contentTypes[$type])) {
    redirect_with_toast('content', 'error', admin_trans('content_error_type'));
}

$content = load_content_by_id_admin($id);

if (!$content) {
    redirect_with_toast('content', 'error', admin_trans('content_error_missing'), ['type' => $type]);
}

$typeLabel = $contentTypes[$type]['label'] ?? ucfirst($type);
$pageTitle = admin_trans('versions_page_title', ['title' => $content['title']]);

// ----------------------------
// Restore
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $versionId = (int) ($_POST['version_id'] ?? 0);

    if ($versionId <= 0) {
        redirect_with_toast('content/versions', 'error', admin_trans('versions_error_choose'), ['id' => $id, 'type' => $type]);
    }

    $version = load_content_version($versionId);

    // The version must belong to this item; never trust the posted id alone.
    if (!$version || (int) $version['content_id'] !== $id) {
        redirect_with_toast('content/versions', 'error', admin_trans('versions_error_other_content'), ['id' => $id, 'type' => $type]);
    }

    if (restore_content_version($versionId, ['reason' => 'restore'])) {
        log_activity('content.restored', 'content', $id, admin_trans('versions_restored', ['number' => (int) $version['version']]), [
            'id'      => $id,
            'version' => (int) $version['version'],
        ]);

        redirect_with_toast(
            'content/versions',
            'success',
            admin_trans('versions_restored', ['number' => (int) $version['version']]),
            ['id' => $id, 'type' => $type]
        );
    }

    redirect_with_toast('content/versions', 'error', admin_trans('versions_error_restore'), ['id' => $id, 'type' => $type]);
}

// ----------------------------
// View one version
// ----------------------------
$viewId  = (int) ($_GET['version'] ?? 0);
$viewing = null;

if ($viewId > 0) {
    $candidate = load_content_version($viewId);

    if ($candidate && (int) $candidate['content_id'] === $id) {
        $viewing = $candidate;
    }
}

$versions = list_content_versions($id, 50);
$currentRow = content_version_current_row($id);
$currentPayload = $currentRow ? content_version_payload($currentRow) : [];

$editUrl = url('admin/content/edit') . '?type=' . urlencode($type) . '&id=' . $id;
$historyUrl = url('admin/content/versions') . '?type=' . urlencode($type) . '&id=' . $id;

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e($content['title']) ?></h2>
        <p>
            <?= e(admin_trans('versions_title')) ?>
            &middot; <?= (int) count_content_versions($id) ?> <?= e(admin_trans('versions_stored')) ?>
            &middot; <?= e(admin_trans('versions_keeping', ['count' => content_version_keep()])) ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn-small" href="<?= e($editUrl) ?>"><?= e(admin_trans('versions_back')) ?></a>
        <a class="btn-small" href="<?= e(preview_url(url(''))) ?>" target="_blank"><?= e(admin_trans('common_preview')) ?></a>
    </div>
</div>

<?php if ($viewing): ?>
    <?php
    $viewChanges = content_version_changes(content_version_payload($viewing), $currentPayload);
    ?>
    <div class="card version-view">
        <div class="version-view-header">
            <h3>
                <?= e(admin_trans('versions_number', ['number' => (int) $viewing['version']])) ?>
                <span class="status status-<?= e($viewing['status']) ?>"><?= e(content_status_label($viewing['status'])) ?></span>
            </h3>
            <div class="version-view-actions">
                <form method="post" class="inline-form js-confirm-form"
                      data-confirm-title="<?= e(admin_trans('versions_restore')) ?>"
                      data-confirm="<?= e(admin_trans('versions_restore_confirm', ['number' => (int) $viewing['version']])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="version_id" value="<?= (int) $viewing['id'] ?>">
                    <button type="submit" class="btn-small btn-primary"><?= e(admin_trans('versions_restore_this')) ?></button>
                </form>
                <a class="btn-small btn-muted" href="<?= e($historyUrl) ?>"><?= e(admin_trans('common_close')) ?></a>
            </div>
        </div>

        <p class="version-meta">
            <?= e(format_local_datetime((int) $viewing['created_at'], 'Y-m-d H:i')) ?>
            <?php if (!empty($viewing['username'])): ?>
                &middot; <?= e($viewing['username']) ?>
            <?php endif; ?>
            &middot; <?= e(content_version_reason_label((string) $viewing['reason'])) ?>
        </p>

        <?php if ($viewChanges): ?>
            <p class="version-changes">
                <?= e(admin_trans('versions_differs')) ?>:
                <?php foreach ($viewChanges as $change): ?>
                    <span class="badge"><?= e($change) ?></span>
                <?php endforeach; ?>
            </p>
        <?php else: ?>
            <p class="version-changes"><?= e(admin_trans('versions_identical')) ?></p>
        <?php endif; ?>

        <h4><?= e($viewing['title']) ?></h4>

        <?php
        $viewMeta = json_decode((string) ($viewing['meta'] ?? '{}'), true);
        $viewBody = json_decode((string) ($viewing['body'] ?? '[]'), true);
        ?>

        <?php if (is_array($viewMeta) && $viewMeta): ?>
            <details>
                <summary><?= e(admin_trans('versions_metadata')) ?></summary>
                <ul class="version-meta-list">
                    <?php foreach ($viewMeta as $key => $value): ?>
                        <li>
                            <strong><?= e((string) $key) ?>:</strong>
                            <?= e(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>

        <?php if (is_array($viewBody) && $viewBody): ?>
            <details open>
                <summary><?= e(admin_trans('common_components')) ?> (<?= count($viewBody) ?>)</summary>
                <ol class="version-component-list">
                    <?php foreach ($viewBody as $component): ?>
                        <li>
                            <code><?= e((string) ($component['type'] ?? '?')) ?></code>
                            <?php if (!empty($component['props'])): ?>
                                <span class="text-muted"><?= e(implode(', ', array_slice(array_keys($component['props']), 0, 6))) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </details>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (empty($versions)): ?>
    <p class="empty-state"><?= e(admin_trans('versions_empty')) ?></p>
<?php else: ?>
    <div class="card">
        <table class="content-table">
            <thead>
                <tr>
                    <th><?= e(admin_trans('versions_version')) ?></th>
                    <th><?= e(admin_trans('content_title')) ?></th>
                    <th><?= e(admin_trans('common_status')) ?></th>
                    <th><?= e(admin_trans('versions_changed')) ?></th>
                    <th><?= e(admin_trans('common_author')) ?></th>
                    <th class="col-actions"><?= e(admin_trans('common_actions')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($versions as $index => $version): ?>
                <?php
                $payload = content_version_payload($version);
                $changes = content_version_changes($payload, $currentPayload);
                $isNewest = $index === 0;
                ?>
                <tr>
                    <td>
                        <strong>#<?= (int) $version['version'] ?></strong>
                        <?php if ($isNewest): ?>
                            <span class="badge"><?= e(admin_trans('versions_latest')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($version['title']) ?></td>
                    <td>
                        <span class="status status-<?= e($version['status']) ?>">
                            <?= e(content_status_label((string) $version['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <?= e(content_version_reason_label((string) $version['reason'])) ?>
                        <?php if ($changes): ?>
                            <small class="text-muted">(<?= e(implode(', ', $changes)) ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($version['username'] ?? '—') ?>
                        <small class="text-muted"><?= e(format_local_datetime((int) $version['created_at'], 'Y-m-d H:i')) ?></small>
                    </td>
                    <td class="actions">
                        <a class="btn-small"
                           href="<?= e($historyUrl . '&version=' . (int) $version['id']) ?>">
                            <?= e(admin_trans('common_view')) ?>
                        </a>

                        <form method="post" class="inline-form js-confirm-form"
                              data-confirm-title="<?= e(admin_trans('versions_restore')) ?>"
                              data-confirm="<?= e(admin_trans('versions_restore_confirm', ['number' => (int) $version['version']])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="version_id" value="<?= (int) $version['id'] ?>">
                            <button type="submit" class="btn-small btn-primary"><?= e(admin_trans('common_restore')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('versions_title')) ?></h3>
<p><?= e(admin_trans('versions_help')) ?></p>
<ul>
    <li><?= e(admin_trans('versions_restore_help')) ?></li>
    <li><?= e(admin_trans('versions_retention_help', ['count' => content_version_keep()])) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'editor', 'section' => 'versions-and-undo'];

include CMS_PATH . '/admin/partials/layout.php';

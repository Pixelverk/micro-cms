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

    // An autosave is unsaved work, not a state the page was ever in. Writing it
    // back would save whatever status the form happened to carry, which can
    // publish half-finished content, so it is loaded into the editor instead.
    if (($version['reason'] ?? '') === 'autosave') {
        header('Location: ' . url('admin/content/edit') . '?id=' . $id . '&type=' . urlencode($type) . '&restore_version=' . $versionId);
        exit;
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
// Compare two versions, or a version with the current state
// ----------------------------
$versions       = list_content_versions($id, 50);
$currentRow     = content_version_current_row($id);
$currentPayload = $currentRow ? content_version_payload($currentRow) : [];

/**
 * Resolve one side of the comparison: "current", or a version of this item.
 *
 * A version id from another item is ignored rather than trusted, exactly as the
 * restore path already does.
 */
$resolveSide = function (string $raw) use ($id, $currentRow, $currentPayload): ?array {
    if ($raw === 'current') {
        return $currentRow
            ? ['version' => null, 'payload' => $currentPayload, 'label' => admin_trans('versions_current')]
            : null;
    }

    $versionId = (int) $raw;

    if ($versionId <= 0) {
        return null;
    }

    $candidate = load_content_version($versionId);

    if (!$candidate || (int) $candidate['content_id'] !== $id) {
        return null;
    }

    return [
        'version' => $candidate,
        'payload' => content_version_payload($candidate),
        'label'   => admin_trans('versions_number', ['number' => (int) $candidate['version']]),
    ];
};

$fromRaw = (string) ($_GET['from'] ?? '');
$toRaw   = (string) ($_GET['to'] ?? '');

$from = $fromRaw !== '' ? $resolveSide($fromRaw) : null;
$to   = $toRaw !== '' ? $resolveSide($toRaw) : null;

// One side alone means "against the current state".
if ($from && !$to) {
    $to = $resolveSide('current');
} elseif ($to && !$from) {
    $from = $resolveSide('current');
}

// What the two selects show: the active sides, or the newest version against
// the current state.
$fromSelected = 'current';

if ($fromRaw !== '') {
    $fromSelected = $from && $from['version'] ? (int) $from['version']['id'] : 'current';
} elseif ($versions) {
    $fromSelected = (int) $versions[0]['id'];
}

$toSelected = $toRaw !== ''
    ? ($to && $to['version'] ? (int) $to['version']['id'] : 'current')
    : 'current';

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

<?php if ($versions): ?>
    <form method="get" class="version-compare">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <label class="version-compare-field">
            <span class="field-label"><?= e(admin_trans('versions_compare_from')) ?></span>
            <select name="from" class="field-input">
                <option value="current"<?= $fromSelected === 'current' ? ' selected' : '' ?>><?= e(admin_trans('versions_current')) ?></option>
                <?php foreach ($versions as $version): ?>
                    <option value="<?= (int) $version['id'] ?>"<?= (int) $fromSelected === (int) $version['id'] ? ' selected' : '' ?>>
                        #<?= (int) $version['version'] ?> &middot; <?= e(format_local_datetime((int) $version['created_at'], 'Y-m-d H:i')) ?> &middot; <?= e(content_version_reason_label((string) $version['reason'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="version-compare-field">
            <span class="field-label"><?= e(admin_trans('versions_compare_to')) ?></span>
            <select name="to" class="field-input">
                <option value="current"<?= $toSelected === 'current' ? ' selected' : '' ?>><?= e(admin_trans('versions_current')) ?></option>
                <?php foreach ($versions as $version): ?>
                    <option value="<?= (int) $version['id'] ?>"<?= (int) $toSelected === (int) $version['id'] ? ' selected' : '' ?>>
                        #<?= (int) $version['version'] ?> &middot; <?= e(format_local_datetime((int) $version['created_at'], 'Y-m-d H:i')) ?> &middot; <?= e(content_version_reason_label((string) $version['reason'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <button type="submit" class="btn-primary"><?= e(admin_trans('versions_compare')) ?></button>
    </form>
<?php endif; ?>

<?php if ($from && $to): ?>
    <?php
    $pairChanges = content_version_changes($from['payload'], $to['payload']);
    $diffLines   = version_text_diff(content_version_lines($from['payload']), content_version_lines($to['payload']));
    $fromVersion = $from['version'];
    ?>
    <div class="card version-view">
        <div class="version-view-header">
            <h3><?= e($from['label']) ?> &rarr; <?= e($to['label']) ?></h3>
            <div class="version-view-actions">
                <?php if ($fromVersion && ($fromVersion['reason'] ?? '') === 'autosave'): ?>
                    <a class="btn-small btn-primary" href="<?= e($editUrl . '&restore_version=' . (int) $fromVersion['id']) ?>"><?= e(admin_trans('editor_autosave_review')) ?></a>
                <?php elseif ($fromVersion): ?>
                    <form method="post" class="inline-form js-confirm-form"
                          data-confirm-title="<?= e(admin_trans('versions_restore')) ?>"
                          data-confirm="<?= e(admin_trans('versions_restore_confirm', ['number' => (int) $fromVersion['version']])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="version_id" value="<?= (int) $fromVersion['id'] ?>">
                        <button type="submit" class="btn-small btn-primary"><?= e(admin_trans('versions_restore_this')) ?></button>
                    </form>
                <?php endif; ?>
                <a class="btn-small btn-muted" href="<?= e($historyUrl) ?>"><?= e(admin_trans('common_close')) ?></a>
            </div>
        </div>

        <ul class="version-meta-list">
            <?php foreach (['from' => $from, 'to' => $to] as $side): ?>
                <?php if (!$side['version']) { continue; } ?>
                <li>
                    <strong><?= e($side['label']) ?>:</strong>
                    <?= e(format_local_datetime((int) $side['version']['created_at'], 'Y-m-d H:i')) ?>
                    <?php if (!empty($side['version']['username'])): ?>&middot; <?= e($side['version']['username']) ?><?php endif; ?>
                    &middot; <?= e(content_version_reason_label((string) $side['version']['reason'])) ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($pairChanges): ?>
            <p class="version-changes">
                <?= e(admin_trans('versions_differs')) ?>:
                <?php foreach ($pairChanges as $change): ?>
                    <span class="badge"><?= e($change) ?></span>
                <?php endforeach; ?>
            </p>
        <?php else: ?>
            <p class="version-changes"><?= e(admin_trans('versions_identical')) ?></p>
        <?php endif; ?>

        <div class="diff">
            <?php foreach ($diffLines as $line): ?>
                <div class="diff-line diff-<?= e($line['op']) ?>"><span class="diff-marker" aria-hidden="true"><?= $line['op'] === 'add' ? '+' : ($line['op'] === 'del' ? '-' : ' ') ?></span><?= e($line['text']) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($versions)): ?>
    <p class="empty-state"><?= e(admin_trans('versions_empty')) ?></p>
<?php else: ?>
    <table class="content-table version-table">
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
                <td class="actions col-actions">
                    <a class="btn-small btn-secondary"
                       href="<?= e($historyUrl . '&from=' . (int) $version['id'] . '&to=current') ?>">
                        <?= e(admin_trans('versions_compare')) ?>
                    </a>

                    <?php if (($version['reason'] ?? '') === 'autosave'): ?>
                        <a class="btn-small btn-primary" href="<?= e($editUrl . '&restore_version=' . (int) $version['id']) ?>"><?= e(admin_trans('editor_autosave_review')) ?></a>
                    <?php else: ?>
                        <form method="post" class="inline-form js-confirm-form"
                              data-confirm-title="<?= e(admin_trans('versions_restore')) ?>"
                              data-confirm="<?= e(admin_trans('versions_restore_confirm', ['number' => (int) $version['version']])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="version_id" value="<?= (int) $version['id'] ?>">
                            <button type="submit" class="btn-small btn-primary"><?= e(admin_trans('common_restore')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('versions_title')) ?></h3>
<p><?= e(admin_trans('versions_help')) ?></p>
<ul>
    <li><?= e(admin_trans('versions_restore_help')) ?></li>
    <li><?= e(admin_trans('versions_compare_help')) ?></li>
    <li><?= e(admin_trans('versions_retention_help', ['count' => content_version_keep()])) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'editor', 'section' => 'versions-and-undo'];

include CMS_PATH . '/admin/partials/layout.php';

<?php
declare(strict_types=1);

$pageTitle = admin_trans('nav_utilities');

/*
|--------------------------------------------------------------------------
| Content package requests
|--------------------------------------------------------------------------
| Import is two steps: the preview says what would happen, and the second
| request applies it. An upload is stashed between them, so the apply needs
| only the token; the theme's own demo files are simply read twice.
|--------------------------------------------------------------------------
*/

/**
 * The sections the posted form asked for.
 *
 * @return array{content: bool, settings: bool}
 */
function utilities_package_sections(): array
{
    $wanted = (array) ($_POST['sections'] ?? []);

    return [
        'content'  => in_array('content', $wanted, true),
        'settings' => in_array('settings', $wanted, true),
    ];
}

/**
 * Read the chosen source and plan it, without writing anything.
 *
 * @param array{content: bool, settings: bool} $sections
 * @return array{errors: list<string>, plan: array<string, mixed>, package: array<string, mixed>, source: string, files: int, token: string, sections: array{content: bool, settings: bool}}
 */
function utilities_import_read(array $sections, string $stashedToken = ''): array
{
    $documents = [];
    $errors    = [];
    $source    = 'demo';
    $files     = 0;
    $token     = '';

    if ($stashedToken !== '') {
        $read   = content_package_stash_read($stashedToken);
        $source = 'files';
        $token  = $stashedToken;
        $files  = count($read['documents']);
        $documents = $read['documents'];
        $errors = $read['errors'];
    } else {
        // A fresh upload on the preview request.
        $uploads = content_package_uploads($_FILES['files'] ?? []);

        if (content_package_uploads_present($uploads)) {
            $stash     = content_package_stash_store($uploads);
            $read      = content_package_stash_read($stash['token']);
            $source    = 'files';
            $token     = $stash['token'];
            $files     = $stash['count'];
            $documents = $read['documents'];
            $errors    = array_merge($stash['errors'], $read['errors']);
        } else {
            $demo      = content_package_theme_demo();
            $documents = $demo['documents'];
            $errors    = $demo['errors'];
        }
    }

    $available = ['content' => false, 'settings' => false];

    foreach ($documents as $document) {
        foreach (['content', 'settings'] as $section) {
            if (isset($document[$section])) {
                $available[$section] = true;
            }
        }
    }

    if (!$sections['content'] && !$sections['settings']) {
        $errors[] = 'Choose content, settings, or both.';
    }

    foreach (['content', 'settings'] as $section) {
        if ($sections[$section] && !$available[$section]) {
            $errors[] = "The package carries no {$section}.";
        }
    }

    // Keep only what was asked for, then plan the result.
    $chosen = [];

    foreach ($documents as $document) {
        if (!$sections['content']) {
            unset($document['content'], $document['taxonomies'], $document['menus']);
        }

        if (!$sections['settings']) {
            unset($document['settings']);
        }

        if (isset($document['content']) || isset($document['settings'])) {
            $chosen[] = $document;
        }
    }

    $merged = content_package_merge($chosen);
    $plan   = content_package_plan($merged['package']);

    return [
        'errors'   => array_merge($errors, $merged['problems']),
        'plan'     => $plan,
        'package'  => $merged['package'],
        'source'   => $source,
        'files'    => $files,
        'token'    => $token,
        // The apply form repeats these, so it imports what was previewed.
        'sections' => $sections,
    ];
}

// ----------------------------
// Handle POST actions
// ----------------------------
$message = '';
$toastType = 'success';
$importPreview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['utility_action'] ?? '';

    // Allow only known actions
    $allowedActions = ['clear_cache', 'warm_cache', 'export_static', 'export_backup', 'reset_analytics', 'clear_trash', 'regenerate_sitemap', 'publish_due', 'run_migrations', 'search_reindex', 'export_package', 'import_preview', 'import_apply'];

    if (in_array($action, $allowedActions, true)) {
        switch ($action) {

            case 'export_package':
                // One button, one document, so the request names what it wants.
                $section = (string) ($_POST['section'] ?? '');

                if ($section === 'content') {
                    $filename = 'content.json';
                    $json     = content_package_json(content_package_export_content());
                } elseif ($section === 'settings') {
                    $filename = 'settings.json';
                    $json     = content_package_json(content_package_export_settings());
                } else {
                    redirect_with_toast('utilities', 'error', admin_trans('utilities_export_none'));
                }

                log_activity('utility.content_export', 'utility', null, $filename, ['bytes' => strlen($json)]);

                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($json));
                echo $json;
                exit;

            case 'import_preview':
                // The preview is page content, not a toast, so nothing is
                // redirected: the report renders below the form.
                content_package_stash_prune();
                $importPreview = utilities_import_read(utilities_package_sections());

                // A refused preview has nothing to apply, so its stash goes now
                // rather than waiting for the hourly prune.
                if ($importPreview['errors'] || $importPreview['plan']['problems']) {
                    content_package_stash_forget($importPreview['token']);
                    $importPreview['token'] = '';
                }
                break;

            case 'import_apply':
                $sections = utilities_package_sections();
                $stashed  = (string) ($_POST['token'] ?? '');

                // The token was checked when it was stashed, so a demo import
                // (no token) and a stashed one take the same path.
                $read    = utilities_import_read($sections, $stashed);
                $problem = array_merge($read['errors'], $read['plan']['problems']);

                if ($problem) {
                    if ($stashed !== '') {
                        content_package_stash_forget($stashed);
                    }

                    redirect_with_toast('utilities', 'error', admin_trans('utilities_import_refused') . ': ' . implode(' ', $problem));
                }

                try {
                    $summary = content_package_import($read['package']);
                } catch (Throwable $exception) {
                    debug_log('content import failed: ' . $exception->getMessage());

                    if ($stashed !== '') {
                        content_package_stash_forget($stashed);
                    }

                    redirect_with_toast('utilities', 'error', admin_trans('utilities_import_failed'));
                }

                if ($stashed !== '') {
                    content_package_stash_forget($stashed);
                }

                log_activity('utility.content_import', 'utility', null, $read['source'], $summary);

                $message = admin_trans('utilities_import_done', [
                    'content'    => $summary['content'],
                    'taxonomies' => $summary['taxonomies'],
                    'menus'      => $summary['menus'],
                    'settings'   => $summary['settings'],
                ]);

                // Anything the import had to skip or reset matters as much as
                // the counts, so it rides along in the same toast.
                foreach ($summary['warnings'] as $warning) {
                    $message .= ' ' . $warning;
                    $toastType = 'error';
                }
                break;

            case 'clear_cache':
                invalidate_cache();
                log_activity('utility.cache_cleared', 'utility', null, admin_trans('utilities_log_cache_cleared'), []);
                $message = admin_trans('utilities_success_cache');
                break;

            case 'reset_analytics':
                $removed = analytics_clear();
                log_activity('utility.analytics_reset', 'utility', null, $removed . ' page view(s)', []);
                $message = admin_trans('utilities_success_analytics', ['count' => $removed]);
                break;

            case 'clear_trash':
                $purged = content_empty_trash();
                log_activity('utility.trash_cleared', 'utility', null, $purged . ' item(s)', []);
                $message = $purged
                    ? admin_trans('utilities_success_trash', ['count' => $purged])
                    : admin_trans('utilities_success_trash_empty');
                break;

            case 'warm_cache':
                $result = warm_cache();
                log_activity('utility.cache_warmed', 'utility', null, $result['rendered'] . ' page(s)', $result);
                $message = admin_trans('utilities_success_warm', ['count' => $result['rendered']]);

                if ($result['failed']) {
                    $toastType = 'error';
                    $message  .= ' ' . admin_trans('utilities_warm_failed', ['list' => implode(', ', $result['failed'])]);
                }
                break;

            case 'export_static':
                try {
                    $archive = export_static_site();
                } catch (Throwable $exception) {
                    redirect_with_toast('utilities', 'error', $exception->getMessage());
                }

                log_activity('utility.static_export', 'utility', null, basename($archive), ['bytes' => filesize($archive)]);

                // Stream the archive and discard it; a POST body is the one
                // response the browser can save without a download endpoint.
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="static-site.zip"');
                header('Content-Length: ' . filesize($archive));
                readfile($archive);
                @unlink($archive);
                exit;

            case 'export_backup':
                try {
                    $archive = backup_build();
                } catch (Throwable $exception) {
                    redirect_with_toast('utilities', 'error', $exception->getMessage());
                }

                log_activity('utility.backup', 'utility', null, basename($archive), ['bytes' => filesize($archive)]);

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="site-backup.zip"');
                header('Content-Length: ' . filesize($archive));
                readfile($archive);
                @unlink($archive);
                exit;

            case 'regenerate_sitemap':
                save_sitemap();
                log_activity('utility.sitemap', 'utility', null, admin_trans('utilities_log_sitemap'), []);
                $message = admin_trans('utilities_success_sitemap');
                break;

            case 'publish_due':
                $published = publish_due_content();
                log_activity('utility.published_due', 'utility', null, count($published) . ' item(s)', []);
                $message = $published
                    ? admin_trans('utilities_success_published', ['count' => count($published)])
                    : admin_trans('utilities_success_nothing_due');
                break;

            case 'search_reindex':
                $indexed = search_reindex_all();
                log_activity('utility.search_reindex', 'utility', null, $indexed . ' item(s)', []);
                $message = admin_trans('utilities_success_search_reindex', ['count' => $indexed]);
                break;

            case 'run_migrations':
                migrate_reset_marker();
                $ran = migrate_run();
                log_activity('utility.migrations', 'utility', null, count($ran) . ' migration(s)', ['ran' => $ran]);
                $message = $ran
                    ? admin_trans('utilities_success_migrations', ['count' => count($ran), 'list' => implode(', ', $ran)])
                    : admin_trans('utilities_success_up_to_date');
                break;
        }
    } else {
        $toastType = 'error';
        $message = admin_trans('utilities_error_unknown', ['action' => $action]);
    }

    if (!empty($message)) {
        // The toast partial escapes via json_encode, so pass the raw message.
        redirect_with_toast('utilities', $toastType, $message);
    }
}

$trashCount = content_trash_count();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('nav_utilities')) ?></h2>
        <p><?= e(admin_trans('utilities_intro')) ?></p>
    </div>
</div>

<form id="utilities-form" method="post">
    <?= csrf_field() ?>

    <fieldset class="settings-group">
        <legend>
            <?= icon('wrench', 18) ?>
            <?= e(admin_trans('utilities_group_maintenance')) ?>
        </legend>

        <div class="utility-grid">
            <div class="utility-action">
                <div class="utility-action-head">
                    <span class="tile-icon" aria-hidden="true"><?= icon('wrench', 20) ?></span>
                    <h3><?= e(admin_trans('utilities_clear_cache')) ?></h3>
                </div>
                <p><?= e(admin_trans('utilities_clear_cache_help')) ?></p>
                <button type="button" data-action="clear_cache" class="btn">
                    <?= icon('trash', 16) ?><?= e(admin_trans('utilities_clear_cache')) ?>
                </button>
            </div>

            <div class="utility-action">
                <div class="utility-action-head">
                    <span class="tile-icon" aria-hidden="true"><?= icon('clock', 20) ?></span>
                    <h3><?= e(admin_trans('utilities_warm_cache')) ?></h3>
                </div>
                <p><?= e(admin_trans('utilities_warm_cache_help')) ?></p>
                <button type="button" data-action="warm_cache" class="btn">
                    <?= icon('magic-wand', 16) ?><?= e(admin_trans('utilities_warm_cache')) ?>
                </button>
            </div>

            <div class="utility-action">
                <div class="utility-action-head">
                    <span class="tile-icon" aria-hidden="true"><?= icon('open-in-browser', 20) ?></span>
                    <h3><?= e(admin_trans('utilities_sitemap')) ?></h3>
                </div>
                <p><?= e(admin_trans('utilities_help_sitemap')) ?></p>
                <button type="button" data-action="regenerate_sitemap" class="btn">
                    <?= icon('page-star', 16) ?><?= e(admin_trans('utilities_sitemap')) ?>
                </button>
            </div>
        </div>
    </fieldset>

    <fieldset class="settings-group">
        <legend>
            <?= icon('post', 18) ?>
            <?= e(admin_trans('utilities_group_content')) ?>
        </legend>

        <div class="utility-grid">
            <div class="utility-action">
                <div class="utility-action-head">
                    <span class="tile-icon" aria-hidden="true"><?= icon('mail-in', 20) ?></span>
                    <h3><?= e(admin_trans('utilities_publish_due')) ?></h3>
                </div>
                <p><?= e(admin_trans('utilities_publish_due_help')) ?></p>
                <button type="button" data-action="publish_due" class="btn">
                    <?= icon('post', 16) ?><?= e(admin_trans('utilities_publish_due_button')) ?>
                </button>
            </div>

            <div class="utility-action">
                <div class="utility-action-head">
                    <span class="tile-icon" aria-hidden="true"><?= icon('search', 20) ?></span>
                    <h3><?= e(admin_trans('utilities_search_reindex')) ?></h3>
                </div>
                <p><?= e(admin_trans('utilities_search_reindex_help')) ?></p>
                <button type="button" data-action="search_reindex" class="btn">
                    <?= icon('search', 16) ?><?= e(admin_trans('utilities_search_reindex')) ?>
                </button>
            </div>

            <div class="utility-action">
                <div class="utility-action-head">
                    <span class="tile-icon" aria-hidden="true"><?= icon('post', 20) ?></span>
                    <h3><?= e(admin_trans('utilities_clear_trash')) ?></h3>
                </div>
                <?php if ($trashCount): ?>
                    <p><?= e(admin_trans('utilities_clear_trash_help', ['count' => $trashCount])) ?></p>
                    <button type="button" data-action="clear_trash" class="btn">
                        <?= icon('warning-triangle', 16, 'icon-danger') ?><?= e(admin_trans('utilities_clear_trash')) ?>
                    </button>
                <?php else: ?>
                    <p><?= e(admin_trans('utilities_clear_trash_empty')) ?></p>
                    <button type="button" class="btn btn-muted" disabled>
                        <?= icon('warning-triangle', 16) ?><?= e(admin_trans('utilities_clear_trash')) ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="utility-action">
                <div class="utility-action-head">
                    <span class="tile-icon" aria-hidden="true"><?= icon('clipboard-check', 20) ?></span>
                    <h3><?= e(admin_trans('utilities_reset_analytics')) ?></h3>
                </div>
                <p><?= e(admin_trans('utilities_reset_analytics_help')) ?></p>
                <button type="button" data-action="reset_analytics" class="btn">
                    <?= icon('warning-triangle', 16, 'icon-danger') ?><?= e(admin_trans('utilities_reset_analytics')) ?>
                </button>
            </div>
        </div>
    </fieldset>

    <fieldset class="settings-group">
        <legend>
            <?= icon('book', 18) ?>
            <?= e(admin_trans('utilities_group_system')) ?>
        </legend>

        <div class="utility-grid">
            <div class="utility-action">
                <div class="utility-action-head">
                    <span class="tile-icon" aria-hidden="true"><?= icon('expand', 20) ?></span>
                    <h3><?= e(admin_trans('utilities_export_static')) ?></h3>
                </div>
                <p><?= e(admin_trans('utilities_export_static_help')) ?></p>
                <?php if (zip_available()): ?>
                    <button type="button" data-action="export_static" class="btn">
                        <?= icon('download', 16) ?><?= e(admin_trans('utilities_export_static')) ?>
                    </button>
                <?php else: ?>
                    <p class="text-muted text-small"><?= e(admin_trans('utilities_zip_required')) ?></p>
                    <button type="button" class="btn btn-muted" disabled>
                        <?= icon('download', 16) ?><?= e(admin_trans('utilities_export_static')) ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="utility-action">
                <div class="utility-action-head">
                    <span class="tile-icon" aria-hidden="true"><?= icon('book', 20) ?></span>
                    <h3><?= e(admin_trans('utilities_backup')) ?></h3>
                </div>
                <p><?= e(admin_trans('utilities_backup_help')) ?></p>
                <?php if (zip_available()): ?>
                    <button type="button" data-action="export_backup" class="btn">
                        <?= icon('download', 16) ?><?= e(admin_trans('utilities_backup')) ?>
                    </button>
                <?php else: ?>
                    <p class="text-muted text-small"><?= e(admin_trans('utilities_zip_required')) ?></p>
                    <button type="button" class="btn btn-muted" disabled>
                        <?= icon('download', 16) ?><?= e(admin_trans('utilities_backup')) ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="utility-action">
                <div class="utility-action-head">
                    <span class="tile-icon" aria-hidden="true"><?= icon('settings', 20) ?></span>
                    <h3><?= e(admin_trans('utilities_migrations')) ?></h3>
                </div>
                <p><?= e(admin_trans('utilities_migrations_help')) ?></p>
                <button type="button" data-action="run_migrations" class="btn">
                    <?= icon('wrench', 16) ?><?= e(admin_trans('utilities_migrations_button')) ?>
                </button>
            </div>
        </div>
    </fieldset>

    <!-- Hidden input for submitting the chosen action -->
    <input type="hidden" name="utility_action" id="utility-action-input">
</form>

<?php /* The content package is two cards, each opening a dialog: what to
         include, and where a package comes from, are decisions for the dialog
         rather than controls sitting open on the page. */ ?>
<fieldset class="settings-group">
    <legend>
        <?= icon('clipboard-check', 18) ?>
        <?= e(admin_trans('utilities_group_package')) ?>
    </legend>

    <div class="utility-grid">
        <div class="utility-action">
            <div class="utility-action-head">
                <span class="tile-icon" aria-hidden="true"><?= icon('download', 20) ?></span>
                <h3><?= e(admin_trans('utilities_export')) ?></h3>
            </div>
            <p><?= e(admin_trans('utilities_export_help')) ?></p>
            <button type="button" class="btn" data-modal="package-export">
                <?= icon('download', 16) ?><?= e(admin_trans('utilities_export_open')) ?>
            </button>
        </div>

        <div class="utility-action">
            <div class="utility-action-head">
                <span class="tile-icon" aria-hidden="true"><?= icon('open-in-browser', 20) ?></span>
                <h3><?= e(admin_trans('utilities_import')) ?></h3>
            </div>
            <p><?= e(admin_trans('utilities_import_help')) ?></p>
            <button type="button" class="btn" data-modal="package-import">
                <?= icon('open-in-browser', 16) ?><?= e(admin_trans('utilities_import_open')) ?>
            </button>
        </div>
    </div>
</fieldset>

<?php /* Export dialog: one document per button, so there is nothing to tick and
         nothing to zip. */ ?>
<div id="package-export" class="modal-backdrop" hidden>
    <div class="modal">
        <div class="modal-header">
            <h3><?= e(admin_trans('utilities_export_title')) ?></h3>
            <button type="button" class="close-modal" aria-label="<?= e(admin_trans('common_close')) ?>">&times;</button>
        </div>

        <div class="modal-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="utility_action" value="export_package">

                <p><?= e(admin_trans('utilities_export_help')) ?></p>

                <div class="field">
                    <span class="field-label"><?= e(admin_trans('utilities_export_content')) ?></span>
                    <button type="submit" class="btn" name="section" value="content">
                        <?= icon('download', 16) ?><?= e(admin_trans('utilities_export_content_json')) ?>
                    </button>
                </div>

                <div class="field">
                    <span class="field-label"><?= e(admin_trans('utilities_export_settings')) ?></span>
                    <button type="submit" class="btn" name="section" value="settings">
                        <?= icon('download', 16) ?><?= e(admin_trans('utilities_export_settings_json')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
/* Import dialog. A preview is rendered by the same request that posted it, so
   the dialog is simply rendered open again — no JavaScript needed to show the
   report, and the form stays available when a package is refused. */
$importRefused = $importPreview && ($importPreview['errors'] || $importPreview['plan']['problems']);
$importReady   = $importPreview && !$importRefused;
?>
<div id="package-import" class="modal-backdrop"<?= $importPreview ? ' style="display:flex"' : ' hidden' ?>>
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3><?= e(admin_trans('utilities_import_title')) ?></h3>
            <button type="button" class="close-modal" aria-label="<?= e(admin_trans('common_close')) ?>">&times;</button>
        </div>

        <div class="modal-body">
            <?php if (!$importReady): ?>
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="utility_action" value="import_preview">

                    <p class="field-note" id="package-import-source" hidden
                       data-demo="<?= e(admin_trans('utilities_import_source_demo_auto')) ?>"
                       data-files="<?= e(admin_trans('utilities_import_source_files')) ?>"></p>

                    <div class="field">
                        <span class="field-label"><?= e(admin_trans('utilities_import_sections')) ?></span>
                        <label class="field-check">
                            <input type="checkbox" name="sections[]" value="content" checked>
                            <span class="field-label"><?= e(admin_trans('utilities_import_section_content')) ?></span>
                        </label>
                        <label class="field-check">
                            <input type="checkbox" name="sections[]" value="settings" checked>
                            <span class="field-label"><?= e(admin_trans('utilities_import_section_settings')) ?></span>
                        </label>
                    </div>

                    <div class="field">
                        <label class="field-label" for="package-files"><?= e(admin_trans('utilities_import_files')) ?></label>
                        <input class="field-input" type="file" id="package-files" name="files[]" accept="application/json,.json" multiple>
                        <small><?= e(admin_trans('utilities_import_files_help')) ?></small>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary"><?= e(admin_trans('utilities_import_preview')) ?></button>
                    </div>
                </form>

                <?php if ($importRefused): ?>
                    <div class="notice notice-error">
                        <p><strong><?= e(admin_trans('utilities_import_refused')) ?></strong></p>
                        <ul>
                            <?php foreach (array_merge($importPreview['errors'], $importPreview['plan']['problems']) as $problem): ?>
                                <li><?= e($problem) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php $previewPlan = $importPreview['plan']; ?>

                <div class="notice notice-info">
                    <p>
                        <?= $importPreview['source'] === 'files'
                            ? e(admin_trans('utilities_import_source_files', ['count' => $importPreview['files']]))
                            : e(admin_trans('utilities_import_source_demo')) ?>
                    </p>
                    <p>
                        <?= e(admin_trans('utilities_import_replaces', [
                            'delete'     => $previewPlan['delete']['content'] + $previewPlan['delete']['taxonomies'] + $previewPlan['delete']['menus'],
                            'content'    => $previewPlan['create']['content'],
                            'taxonomies' => $previewPlan['create']['taxonomies'],
                            'menus'      => $previewPlan['create']['menus'],
                        ])) ?>
                    </p>
                    <p>
                        <?= e(admin_trans('utilities_import_homepage')) ?>:
                        <?= e($previewPlan['homepage']['ref'] !== '' ? $previewPlan['homepage']['ref'] . ' — ' . $previewPlan['homepage']['note'] : $previewPlan['homepage']['note']) ?>
                    </p>
                </div>

                <h3><?= e(admin_trans('utilities_import_settings_heading')) ?></h3>

                <?php if (!$previewPlan['settings']): ?>
                    <p class="field-note"><?= e(admin_trans('utilities_import_settings_none')) ?></p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th><?= e(admin_trans('utilities_import_settings_heading')) ?></th>
                                <th><?= e(admin_trans('common_updated')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previewPlan['settings'] as $change): ?>
                                <tr>
                                    <td><?= e($change['key']) ?></td>
                                    <td><?= e($change['from']) ?> &rarr; <?= e($change['to']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($previewPlan['warnings']): ?>
                    <div class="notice notice-warning">
                        <p><strong><?= e(admin_trans('utilities_import_warnings')) ?></strong></p>
                        <ul>
                            <?php foreach ($previewPlan['warnings'] as $warning): ?>
                                <li><?= e($warning) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" class="form-actions">
                    <?= csrf_field() ?>
                    <input type="hidden" name="utility_action" value="import_apply">
                    <input type="hidden" name="token" value="<?= e($importPreview['token']) ?>">

                    <?php foreach (['content', 'settings'] as $section): ?>
                        <?php if ($importPreview['sections'][$section]): ?>
                            <input type="hidden" name="sections[]" value="<?= e($section) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <button type="submit" class="btn-danger"><?= e(admin_trans('utilities_import_now')) ?></button>
                    <a class="btn btn-muted" href="<?= e(url('admin/utilities')) ?>"><?= e(admin_trans('common_cancel')) ?></a>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const form = document.getElementById('utilities-form');
const actionInput = document.getElementById('utility-action-input');

const confirmations = <?= json_encode([
    'clear_cache'        => admin_trans('utilities_clear_cache_confirm'),
    'reset_analytics'    => admin_trans('utilities_confirm_reset_analytics'),
    'clear_trash'        => admin_trans('utilities_confirm_clear_trash'),
    'warm_cache'         => admin_trans('utilities_confirm_warm_cache'),
    'export_static'      => admin_trans('utilities_confirm_export_static'),
    'export_backup'      => admin_trans('utilities_confirm_backup'),
    'regenerate_sitemap' => admin_trans('utilities_sitemap_confirm'),
    'publish_due'        => admin_trans('utilities_confirm_publish_due'),
    'run_migrations'     => admin_trans('utilities_confirm_migrations'),
    'search_reindex'     => admin_trans('utilities_search_reindex_confirm'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// Attach click handlers to buttons
form.querySelectorAll('button[data-action]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
        const action = btn.dataset.action;

        e.preventDefault();

        const ok = await confirmModal({
            title: 'Confirm action',
            message: confirmations[action] || 'Are you sure?'
        });

        if (ok) {
            actionInput.value = action;
            form.submit();
        }
    });
});

/* The content package dialogs. A preview is rendered open by the server, so
   opening, closing and Escape are all this needs to do. */
(() => {
    const close = backdrop => {
        backdrop.hidden = true;
        backdrop.style.display = '';
    };

    document.querySelectorAll('[data-modal]').forEach(opener => {
        const backdrop = document.getElementById(opener.dataset.modal);
        if (!backdrop) return;

        opener.addEventListener('click', () => {
            backdrop.hidden = false;
            backdrop.style.display = 'flex';

            const focusable = backdrop.querySelector('input, button');
            if (focusable) focusable.focus();
        });

        // Clicking the backdrop (but not the dialog) closes it.
        backdrop.addEventListener('click', event => {
            if (event.target === backdrop) close(backdrop);
        });

        backdrop.querySelectorAll('.close-modal').forEach(button => {
            button.addEventListener('click', () => close(backdrop));
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;

        document.querySelectorAll('.modal-backdrop:not([hidden])').forEach(close);
    });

    /* Chosen files replace the demo rather than adding to it, and that has to
       be visible before the preview says which one it read. */
    const fileInput = document.getElementById('package-files');
    const sourceLine = document.getElementById('package-import-source');

    if (fileInput && sourceLine) {
        const paintSource = () => {
            const count = fileInput.files.length;
            const text = count ? sourceLine.dataset.files : sourceLine.dataset.demo;

            sourceLine.textContent = text.replace(':count', String(count));
            sourceLine.hidden = false;
        };

        fileInput.addEventListener('change', paintSource);
        paintSource();
    }
})();
</script>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('nav_utilities')) ?></h3>
<p><?= e(admin_trans('utilities_help')) ?></p>
<ul>
    <li><?= e(admin_trans('utilities_help_cache')) ?></li>
    <li><?= e(admin_trans('utilities_help_migrations')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'maintenance'];


include CMS_PATH . '/admin/partials/layout.php';
<?php
declare(strict_types=1);

$pageTitle = admin_trans('nav_utilities');
$username = current_username();

// ----------------------------
// Handle POST actions
// ----------------------------
$message = '';
$toastType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['utility_action'] ?? '';

    // Allow only known actions
    $allowedActions = ['clear_cache', 'warm_cache', 'export_static', 'export_backup', 'reset_analytics', 'clear_trash', 'regenerate_sitemap', 'publish_due', 'run_migrations'];

    if (in_array($action, $allowedActions, true)) {
        switch ($action) {

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
        <h2><?= e(admin_trans('common_hello', ['name' => $username])) ?></h2>
        <p><?= e(admin_trans('utilities_intro')) ?></p>
    </div>
    <div class="page-actions">

    </div>
</div>

<form id="utilities-form" method="post" class="stack">
    <?= csrf_field() ?>

    <div class="utility-action">
        <h3><?= e(admin_trans('utilities_clear_cache')) ?></h3>
        <p><?= e(admin_trans('utilities_clear_cache_help')) ?></p>
        <button type="button" data-action="clear_cache" class="btn btn-warning">
            <?= e(admin_trans('utilities_clear_cache')) ?>
        </button>
    </div>

    <div class="utility-action">
        <h3><?= e(admin_trans('utilities_reset_analytics')) ?></h3>
        <p><?= e(admin_trans('utilities_reset_analytics_help')) ?></p>
        <button type="button" data-action="reset_analytics" class="btn btn-danger">
            <?= e(admin_trans('utilities_reset_analytics')) ?>
        </button>
    </div>

    <div class="utility-action">
        <h3><?= e(admin_trans('utilities_clear_trash')) ?></h3>
        <?php if ($trashCount): ?>
            <p><?= e(admin_trans('utilities_clear_trash_help', ['count' => $trashCount])) ?></p>
            <button type="button" data-action="clear_trash" class="btn btn-danger">
                <?= e(admin_trans('utilities_clear_trash')) ?>
            </button>
        <?php else: ?>
            <p><?= e(admin_trans('utilities_clear_trash_empty')) ?></p>
            <button type="button" class="btn btn-muted" disabled>
                <?= e(admin_trans('utilities_clear_trash')) ?>
            </button>
        <?php endif; ?>
    </div>

    <div class="utility-action">
        <h3><?= e(admin_trans('utilities_warm_cache')) ?></h3>
        <p><?= e(admin_trans('utilities_warm_cache_help')) ?></p>
        <button type="button" data-action="warm_cache" class="btn btn-secondary">
            <?= e(admin_trans('utilities_warm_cache')) ?>
        </button>
    </div>

    <div class="utility-action">
        <h3><?= e(admin_trans('utilities_export_static')) ?></h3>
        <p><?= e(admin_trans('utilities_export_static_help')) ?></p>
        <?php if (zip_available()): ?>
            <button type="button" data-action="export_static" class="btn btn-info">
                <?= e(admin_trans('utilities_export_static')) ?>
            </button>
        <?php else: ?>
            <p class="text-muted text-small"><?= e(admin_trans('utilities_zip_required')) ?></p>
            <button type="button" class="btn btn-muted" disabled>
                <?= e(admin_trans('utilities_export_static')) ?>
            </button>
        <?php endif; ?>
    </div>

    <div class="utility-action">
        <h3><?= e(admin_trans('utilities_backup')) ?></h3>
        <p><?= e(admin_trans('utilities_backup_help')) ?></p>
        <?php if (zip_available()): ?>
            <button type="button" data-action="export_backup" class="btn btn-secondary">
                <?= e(admin_trans('utilities_backup')) ?>
            </button>
        <?php else: ?>
            <p class="text-muted text-small"><?= e(admin_trans('utilities_zip_required')) ?></p>
            <button type="button" class="btn btn-muted" disabled>
                <?= e(admin_trans('utilities_backup')) ?>
            </button>
        <?php endif; ?>
    </div>

    <div class="utility-action">
        <h3><?= e(admin_trans('utilities_sitemap')) ?></h3>
        <p><?= e(admin_trans('utilities_help_sitemap')) ?></p>
        <button type="button" data-action="regenerate_sitemap" class="btn btn-info">
            <?= e(admin_trans('utilities_sitemap')) ?>
        </button>
    </div>

    <div class="utility-action">
        <h3><?= e(admin_trans('utilities_publish_due')) ?></h3>
        <p><?= e(admin_trans('utilities_publish_due_help')) ?></p>
        <button type="button" data-action="publish_due" class="btn btn-primary">
            <?= e(admin_trans('utilities_publish_due_button')) ?>
        </button>
    </div>

    <div class="utility-action">
        <h3><?= e(admin_trans('utilities_migrations')) ?></h3>
        <p><?= e(admin_trans('utilities_migrations_help')) ?></p>
        <button type="button" data-action="run_migrations" class="btn btn-info">
            <?= e(admin_trans('utilities_migrations_button')) ?>
        </button>
    </div>

    <!-- Hidden input for submitting the chosen action -->
    <input type="hidden" name="utility_action" id="utility-action-input">
</form>

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
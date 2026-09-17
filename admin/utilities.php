<?php
declare(strict_types=1);

$pageTitle = 'Utilities';
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
                log_activity('utility.cache_cleared', 'utility', null, 'Cleared all cached pages', []);
                $message = "✅ Cache cleared successfully!";
                break;

            case 'reset_analytics':
                $removed = analytics_clear();
                log_activity('utility.analytics_reset', 'utility', null, $removed . ' page view(s)', []);
                $message = '✅ Analytics reset — ' . $removed . ' page view(s) removed.';
                break;

            case 'clear_trash':
                $purged = content_empty_trash();
                log_activity('utility.trash_cleared', 'utility', null, $purged . ' item(s)', []);
                $message = $purged
                    ? '✅ Trash emptied — ' . $purged . ' item(s) deleted permanently.'
                    : '✅ The trash was already empty.';
                break;

            case 'warm_cache':
                $result = warm_cache();
                log_activity('utility.cache_warmed', 'utility', null, $result['rendered'] . ' page(s)', $result);
                $message = '✅ Warmed ' . $result['rendered'] . ' page(s).';

                if ($result['failed']) {
                    $toastType = 'error';
                    $message  .= ' ⚠️ Could not render: ' . implode(', ', $result['failed']) . '.';
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
                log_activity('utility.sitemap', 'utility', null, 'Regenerated sitemap.xml', []);
                $message = "✅ Sitemap regenerated successfully!";
                break;

            case 'publish_due':
                $published = publish_due_content();
                log_activity('utility.published_due', 'utility', null, count($published) . ' item(s)', []);
                $message = $published
                    ? '✅ Published ' . count($published) . ' scheduled item(s).'
                    : '✅ Nothing was due to publish.';
                break;

            case 'run_migrations':
                migrate_reset_marker();
                $ran = migrate_run();
                log_activity('utility.migrations', 'utility', null, count($ran) . ' migration(s)', ['ran' => $ran]);
                $message = $ran
                    ? '✅ Applied ' . count($ran) . ' migration(s): ' . implode(', ', $ran)
                    : '✅ Database is already up to date.';
                break;
        }
    } else {
        $toastType = 'error';
        $message = "⚠️ Unknown action: " . $action;
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
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p>Run administrative utilities for your site. Use the buttons below to manage cache, sitemap and more.</p>
    </div>
    <div class="page-actions">

    </div>
</div>

<form id="utilities-form" method="post" class="stack">
    <?= csrf_field() ?>

    <div class="utility-action">
        <h3>Clear Cache</h3>
        <p>Remove cached html files to ensure all changes on the site are reflected immediately. Use this if you notice outdated content.</p>
        <button type="button" data-action="clear_cache" class="btn btn-warning">
            Clear Cache
        </button>
    </div>

    <div class="utility-action">
        <h3>Reset Analytics</h3>
        <p>Delete every recorded page view. Use this when you want the reports to start counting from now.</p>
        <button type="button" data-action="reset_analytics" class="btn btn-danger">
            Reset Analytics
        </button>
    </div>

    <div class="utility-action">
        <h3>Clear Trash</h3>
        <?php if ($trashCount): ?>
            <p>Permanently delete the <?= $trashCount ?> <?= $trashCount === 1 ? 'item' : 'items' ?> in the trash, including version history. This cannot be undone.</p>
            <button type="button" data-action="clear_trash" class="btn btn-danger">
                Clear Trash
            </button>
        <?php else: ?>
            <p>The trash is empty. Deleted content waits here until you restore it or it is purged, so it can still be recovered.</p>
            <button type="button" class="btn btn-muted" disabled>
                Clear Trash
            </button>
        <?php endif; ?>
    </div>

    <div class="utility-action">
        <h3>Warm Cache</h3>
        <p>Render and cache every published page now, so the first visitor does not pay the render cost after a bulk edit.</p>
        <button type="button" data-action="warm_cache" class="btn btn-secondary">
            Warm Cache
        </button>
    </div>

    <div class="utility-action">
        <h3>Export Static Site</h3>
        <p>Download a zip of the cached pages, the theme assets and the media library, with asset and media URLs rewritten relative so the pages need no PHP.</p>
        <?php if (zip_available()): ?>
            <button type="button" data-action="export_static" class="btn btn-info">
                Export Static Site
            </button>
        <?php else: ?>
            <p class="text-muted text-small">Requires the PHP zip or phar extension.</p>
            <button type="button" class="btn btn-muted" disabled>
                Export Static Site
            </button>
        <?php endif; ?>
    </div>

    <div class="utility-action">
        <h3>Download Backup</h3>
        <p>Download a zip of the database, the media library and the sitemap — the data, not the pages. Keep a copy somewhere safe before a host move. Restore is manual; see the documentation.</p>
        <?php if (zip_available()): ?>
            <button type="button" data-action="export_backup" class="btn btn-secondary">
                Download Backup
            </button>
        <?php else: ?>
            <p class="text-muted text-small">Requires the PHP zip or phar extension.</p>
            <button type="button" class="btn btn-muted" disabled>
                Download Backup
            </button>
        <?php endif; ?>
    </div>

    <div class="utility-action">
        <h3>Regenerate Sitemap</h3>
        <p>Rebuild the sitemap.xml file to ensure search engines have the latest URLs from your site.</p>
        <button type="button" data-action="regenerate_sitemap" class="btn btn-info">
            Regenerate Sitemap
        </button>
    </div>

    <div class="utility-action">
        <h3>Publish Due Content</h3>
        <p>Publish anything whose scheduled time has passed. The site also does this automatically after a visit, so this is for hosts with little traffic.</p>
        <button type="button" data-action="publish_due" class="btn btn-primary">
            Publish Due Content Now
        </button>
    </div>

    <div class="utility-action">
        <h3>Run Database Migrations</h3>
        <p>Apply any schema updates included in a newer version of the CMS. Safe to run more than once.</p>
        <button type="button" data-action="run_migrations" class="btn btn-info">
            Run Migrations
        </button>
    </div>

    <!-- Hidden input for submitting the chosen action -->
    <input type="hidden" name="utility_action" id="utility-action-input">
</form>

<script>
const form = document.getElementById('utilities-form');
const actionInput = document.getElementById('utility-action-input');

const confirmations = {
    clear_cache: 'Are you sure you want to clear the cache?',
    reset_analytics: 'Delete all recorded page views? This cannot be undone.',
    clear_trash: 'Permanently delete everything in the trash? This cannot be undone.',
    warm_cache: 'Render and cache every published page?',
    export_static: 'Warm the cache and download a static copy of the site?',
    export_backup: 'Download a backup of the database and media?',
    regenerate_sitemap: 'Are you sure you want to regenerate the sitemap.xml?',
    publish_due: 'Publish every scheduled item that is due?',
    run_migrations: 'Apply any pending database migrations?'
};

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
<h3><?= e(admin_trans('utilities')) ?></h3>
<p><?= e(admin_trans('utilities_help')) ?></p>
<ul>
    <li><?= e(admin_trans('utilities_cache_help')) ?></li>
    <li><?= e(admin_trans('utilities_migrations_help')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'maintenance'];


include CMS_PATH . '/admin/partials/layout.php';
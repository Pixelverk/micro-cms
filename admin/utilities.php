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
    $allowedActions = ['clear_cache', 'regenerate_sitemap', 'publish_due', 'run_migrations'];

    if (in_array($action, $allowedActions, true)) {
        switch ($action) {

            case 'clear_cache':
                invalidate_cache();
                log_activity('utility.cache_cleared', 'utility', null, 'Cleared all cached pages', []);
                $message = "✅ Cache cleared successfully!";
                break;

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
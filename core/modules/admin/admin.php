<?php
declare(strict_types=1);



/**
 * Minimal 403 page.
 */
function render_admin_forbidden(string $capability): void
{
    if (is_file(CMS_PATH . '/admin/partials/layout.php')) {
        ob_start();
        ?>
        <div class="page-header">
            <div class="page-title">
                <h2><?= e(admin_trans('error_not_allowed')) ?></h2>
                <p><?= e(admin_trans('error_not_allowed_help')) ?></p>
            </div>
            <div class="page-actions">
                <a class="btn-small" href="<?= e(url('admin/dashboard')) ?>"><?= e(admin_trans('nav_dashboard')) ?></a>
            </div>
        </div>
        <p class="text-muted text-small">
            <?= e(admin_trans('error_required_permission')) ?>: <code><?= e($capability) ?></code>
        </p>
        <?php
        $content = ob_get_clean();

        include CMS_PATH . '/admin/partials/layout.php';
        return;
    }

    exit('Not allowed');
}


/**
 * URL for an admin asset, stamped with its modification time.
 *
 * Without the stamp a browser happily serves a stale main.js against freshly
 * rendered HTML (which is exactly how a fixed confirm-dialog bug reappeared
 * from cache).
 */
function admin_asset(string $path): string
{
    $relative = ltrim($path, '/');

    return version_asset_url(url($relative), CMS_PATH . '/' . $relative);
}

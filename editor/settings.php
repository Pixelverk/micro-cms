<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';
require_once __DIR__ . '/_core/settings.php';

require_login();

$pageTitle = 'Settings';
$username = $_SESSION['user_id'] ?? 'User';

// Load current settings
$settings = load_settings();
$siteTitle    = $settings['site_title'];
$homepageSlug = $settings['homepage_slug'];

// Load pages for homepage select
$pages = list_pages();

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $siteTitle    = trim($_POST['site_title'] ?? '');
    $homepageSlug = $_POST['homepage_slug'] ?? '';

    if ($siteTitle === '') {
        redirect_with_toast(
            'settings.php',
            'error',
            'Site title cannot be empty.'
        );
    }

    // Optional safety: ensure homepage exists
    $validSlugs = array_column($pages, 'slug');
    if ($homepageSlug && !in_array($homepageSlug, $validSlugs, true)) {
        redirect_with_toast(
            'settings.php',
            'error',
            'Selected homepage does not exist.'
        );
    }

    set_setting('site_title', $siteTitle);
    set_setting('homepage_slug', $homepageSlug);

    redirect_with_toast(
        'settings.php',
        'success',
        'Settings saved successfully.'
    );
}

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username); ?> 👋</h2>
        <p>Manage site and CMS settings below.</p>
    </div>
    <div class="page-actions">
        <button type="submit" form="settings">Save Settings</button>
    </div>
</div>

<form id="settings" method="post" class="form-card">

    <fieldset>
        <legend>General</legend>

        <label>
            Site title:
            <input
                type="text"
                name="site_title"
                value="<?= e($siteTitle) ?>"
                required
            >
        </label>

        <label>
            Homepage:
            <select name="homepage_slug">
                <option value="">— Select homepage —</option>

                <?php foreach ($pages as $page): ?>
                    <option
                        value="<?= e($page['slug']) ?>"
                        <?= $page['slug'] === $homepageSlug ? 'selected' : '' ?>
                    >
                        <?= e($page['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </fieldset>

</form>

<?php
$content = ob_get_clean();
include __DIR__ . '/_partials/layout.php';

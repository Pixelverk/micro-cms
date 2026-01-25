<?php

$pageTitle = 'Utilities';
$username = $_SESSION['user_id'] ?? 'User';

// ----------------------------
// Handle POST actions
// ----------------------------
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['utility_action'] ?? '';

    // Allow only known actions
    $allowedActions = ['clear_cache', 'regenerate_sitemap'];

    if (in_array($action, $allowedActions, true)) {
        switch ($action) {

            case 'clear_cache':
                invalidate_cache();
                $message = "✅ Cache cleared successfully!";
                break;

            case 'regenerate_sitemap':
                save_sitemap();
                $message = "✅ Sitemap regenerated successfully!";
                break;
        }
    } else {
        $message = "⚠️ Unknown action: " . htmlspecialchars($action);
    }

    if (!empty($message)) {
        redirect_with_toast('utilities', 'success', e($message));
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

<form id="utilities-form" method="post" style="display:flex; flex-direction:column; gap:1rem;">

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

    <!-- Hidden input for submitting the chosen action -->
    <input type="hidden" name="utility_action" id="utility-action-input">
</form>

<script>
const form = document.getElementById('utilities-form');
const actionInput = document.getElementById('utility-action-input');

// Attach click handlers to buttons
form.querySelectorAll('button[data-action]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
        const action = btn.dataset.action;

        let message = '';
        if (action === 'clear_cache') message = 'Are you sure you want to clear the cache?';
        if (action === 'regenerate_sitemap') message = 'Are you sure you want to regenerate the sitemap.xml?';

        e.preventDefault();

        const ok = await confirmModal({
            title: 'Confirm action',
            message: message
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

include __DIR__ . '/partials/layout.php';
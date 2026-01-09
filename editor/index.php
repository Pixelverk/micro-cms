<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

$pageTitle = 'Dashboard';
$username = $_SESSION['user_id'] ?? 'User';

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Welcome, <?php echo htmlspecialchars($username); ?> 👋</h2>
        <p>Use the tools below to manage your site.</p>
    </div>
    <div class="page-actions">
    </div>
</div>

<div class="cards">
    <div class="card">
        <h2>Pages</h2>
        <p>View, edit, add, or remove pages.</p>
        <a href="page-list.php">Manage pages →</a>
    </div>

    <div class="card">
        <h2>Users</h2>
        <p>Create and manage editor accounts.</p>
        <a href="user-list.php">Manage users →</a>
    </div>

    <div class="card">
        <h2>Site Preview</h2>
        <p>Open the public site in a new tab.</p>
        <a href="/" target="_blank">View site →</a>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/_partials/layout.php';
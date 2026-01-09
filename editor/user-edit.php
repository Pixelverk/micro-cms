<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/auth.php';

require_login();

$username = $_SESSION['user_id'] ?? 'User';
$editUsername = $_GET['username'] ?? '';

if (!$editUsername || !user_exists($editUsername)) {
    redirect_with_toast('user-list.php', 'error', 'User not found');
}

$pageTitle = 'Edit User: ' . $editUsername;

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= htmlspecialchars($username) ?> 👋</h2>
        <p>Editing user: <strong><?= htmlspecialchars($editUsername) ?></strong></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="edit">Save Changes</button>
    </div>
</div>

<form id="edit" method="post" action="user-save.php" class="form-card">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="username" value="<?= htmlspecialchars($editUsername) ?>">

    <fieldset>
        <legend>Update Password</legend>

        <label>
            New Password:
            <input type="password" name="password" placeholder="Leave blank to keep current password">
        </label>

        <label>
            Confirm New Password:
            <input type="password" name="password_confirm" placeholder="Leave blank to keep current password">
        </label>
    </fieldset>
</form>

<?php
$content = ob_get_clean();
include __DIR__ . '/_partials/layout.php';
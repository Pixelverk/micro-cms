<?php

$username = $_SESSION['user_id'] ?? 'User';
$editUsername = $_GET['username'] ?? '';

if (!$editUsername || !user_exists($editUsername)) {
    redirect_with_toast('user-list', 'error', 'User not found');
}

$pageTitle = 'Edit User: ' . $editUsername;

// Load user data
$users = load_users();
$user = $users[$editUsername] ?? [];

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p>Editing user: <strong><?= e($editUsername) ?></strong></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="edit-user">Save Changes</button>
    </div>
</div>

<form id="edit-user" method="post" action="<?= url('admin/user-save') ?>" class="form-card">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="original_username" value="<?= e($editUsername) ?>">

    <fieldset>
        <legend>User Info</legend>

        <label>
            Username:
            <input type="text" name="username" value="<?= e($user['username'] ?? '') ?>" readonly>
            <small>Username cannot be changed.</small>
        </label>

        <label>
            First Name:
            <input type="text" name="first_name" value="<?= e($user['first_name'] ?? '') ?>">
        </label>

        <label>
            Last Name:
            <input type="text" name="last_name" value="<?= e($user['last_name'] ?? '') ?>">
        </label>

        <label>
            Email:
            <input type="email" name="email" value="<?= e($user['email'] ?? '') ?>">
        </label>
    </fieldset>

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
include __DIR__ . '/partials/layout.php';
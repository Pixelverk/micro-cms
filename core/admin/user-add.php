<?php

$pageTitle = 'Add User';
$username = $_SESSION['user_id'] ?? 'User';

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Welcome, <?php echo e($username); ?> 👋</h2>
        <p>Create a new user</p>
    </div>
    <div class="page-actions">
        <button type="submit" form="create">Create User</button>
    </div>
</div>

<form id="create" method="post" action="<?= url('admin/user-save') ?>" class="form-card">
    <input type="hidden" name="action" value="create">

    <fieldset>
        <legend>User Details</legend>

        <label>
            Username:
            <input
                type="text"
                name="username"
                required
                autocomplete="off"
            >
        </label>

        <label>
            Password:
            <input
                type="password"
                name="password"
                required
            >
        </label>

        <label>
            Confirm Password:
            <input
                type="password"
                name="password_confirm"
                required
            >
        </label>
    </fieldset>

</form>

<?php
$content = ob_get_clean();
include __DIR__ . '/partials/layout.php';
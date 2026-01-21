<?php

$pageTitle = 'Add User';
$username = $_SESSION['user_id'] ?? 'User';

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p>Create a new user</p>
    </div>
    <div class="page-actions">
        <button type="submit" form="create-user">Create User</button>
    </div>
</div>

<form id="create-user" method="post" action="<?= url('admin/user-save') ?>" class="form-card">
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
                placeholder="Enter username"
            >
        </label>

        <label>
            First Name:
            <input
                type="text"
                name="first_name"
                placeholder="Optional"
            >
        </label>

        <label>
            Last Name:
            <input
                type="text"
                name="last_name"
                placeholder="Optional"
            >
        </label>

        <label>
            Email:
            <input
                type="email"
                name="email"
                placeholder="Optional"
            >
        </label>

        <label>
            Password:
            <input
                type="password"
                name="password"
                required
                placeholder="Enter password"
            >
        </label>

        <label>
            Confirm Password:
            <input
                type="password"
                name="password_confirm"
                required
                placeholder="Confirm password"
            >
        </label>
    </fieldset>

</form>

<?php
$content = ob_get_clean();
include __DIR__ . '/partials/layout.php';
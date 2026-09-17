<?php

$pageTitle = 'Add User';
$username = $_SESSION['user_id'] ?? 'User';

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p><?= e(admin_trans('create_user')) ?></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="create-user"><?= e(admin_trans('create_user')) ?></button>
    </div>
</div>

<form id="create-user" method="post" action="<?= url('admin/user/save') ?>" class="form-card">
    <input type="hidden" name="action" value="create">

    <fieldset>
        <legend><?= e(admin_trans('user_details')) ?></legend>

        <label>
            <?= e(admin_trans('username')) ?>:
            <input
                type="text"
                name="username"
                required
                autocomplete="off"
                placeholder="<?= e(admin_trans('enter_username')) ?>"
            >
        </label>

        <label>
            <?= e(admin_trans('first_name')) ?>:
            <input
                type="text"
                name="first_name"
                placeholder="<?= e(admin_trans('optional')) ?>"
            >
        </label>

        <label>
            <?= e(admin_trans('last_name')) ?>:
            <input
                type="text"
                name="last_name"
                placeholder="<?= e(admin_trans('optional')) ?>"
            >
        </label>

        <label>
            <?= e(admin_trans('email')) ?>:
            <input
                type="email"
                name="email"
                placeholder="<?= e(admin_trans('optional')) ?>"
            >
        </label>

        <label>
            <?= e(admin_trans('password')) ?>:
            <input
                type="password"
                name="password"
                required
                placeholder="<?= e(admin_trans('enter_password')) ?>"
            >
        </label>

        <label>
            <?= e(admin_trans('confirm_password')) ?>:
            <input
                type="password"
                name="password_confirm"
                required
                placeholder="<?= e(admin_trans('confirm_password_placeholder')) ?>"
            >
        </label>
    </fieldset>

</form>

<?php
$content = ob_get_clean();
include CMS_PATH . '/admin/partials/layout.php';
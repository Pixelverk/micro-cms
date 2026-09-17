<?php

$pageTitle = 'Add User';
$username = current_username();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p><?= e(admin_trans('user_create')) ?></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="create-user"><?= e(admin_trans('user_create')) ?></button>
    </div>
</div>

<form id="create-user" method="post" action="<?= url('admin/user/save') ?>" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">

    <fieldset>
        <legend><?= e(admin_trans('user_details')) ?></legend>

        <label>
            <?= e(admin_trans('user_username')) ?>:
            <input
                type="text"
                name="username"
                required
                autocomplete="off"
                placeholder="<?= e(admin_trans('user_username_placeholder')) ?>"
            >
        </label>

        <label>
            <?= e(admin_trans('user_first_name')) ?>:
            <input
                type="text"
                name="first_name"
                placeholder="<?= e(admin_trans('common_optional')) ?>"
            >
        </label>

        <label>
            <?= e(admin_trans('user_last_name')) ?>:
            <input
                type="text"
                name="last_name"
                placeholder="<?= e(admin_trans('common_optional')) ?>"
            >
        </label>

        <label>
            <?= e(admin_trans('user_email')) ?>:
            <input
                type="email"
                name="email"
                placeholder="<?= e(admin_trans('common_optional')) ?>"
            >
        </label>

        <label>
            <?= e(admin_trans('user_password')) ?>:
            <input
                type="password"
                name="password"
                required
                placeholder="<?= e(admin_trans('user_password_placeholder')) ?>"
            >
        </label>

        <label>
            <?= e(admin_trans('user_role')) ?>:
            <select name="role">
                <?php foreach (admin_roles() as $roleCode): ?>
                    <option value="<?= e($roleCode) ?>" <?= $roleCode === 'author' ? 'selected' : '' ?>>
                        <?= e(admin_role_label($roleCode)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small><?= e(admin_trans('user_help_role')) ?></small>
        </label>

        <label>
            <?= e(admin_trans('user_confirm_password')) ?>:
            <input
                type="password"
                name="password_confirm"
                required
                placeholder="<?= e(admin_trans('user_confirm_password_placeholder')) ?>"
            >
        </label>
    </fieldset>

</form>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('user_create')) ?></h3>
<p><?= e(admin_trans('user_help_form')) ?></p>
<p><?= e(admin_trans('user_help_role')) ?></p>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'roles'];

include CMS_PATH . '/admin/partials/layout.php';
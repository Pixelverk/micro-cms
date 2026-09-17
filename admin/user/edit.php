<?php

$username = current_username();
$editUsername = $_GET['username'] ?? '';

// Load user data; the lookup doubles as the existence check.
$users = load_users();
$user = $users[$editUsername] ?? [];

if (!$editUsername || !$user) {
    redirect_with_toast('user', 'error', admin_trans('user_error_not_found'));
}

$pageTitle = admin_trans('user_edit_title', ['name' => $editUsername]);
$adminLanguages = admin_languages();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('common_hello', ['name' => $username])) ?></h2>
        <p><?= e(admin_trans('user_editing', ['name' => $editUsername])) ?></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="edit-user"><?= e(admin_trans('common_save')) ?></button>
    </div>
</div>

<form id="edit-user" method="post" action="<?= url('admin/user/save') ?>" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="original_username" value="<?= e($editUsername) ?>">

    <fieldset>
        <legend><?= e(admin_trans('user_info')) ?></legend>

        <label>
            <?= e(admin_trans('user_username')) ?>:
            <input type="text" name="username" value="<?= e($user['username'] ?? '') ?>" readonly>
            <small><?= e(admin_trans('user_username_fixed')) ?></small>
        </label>

        <label>
            <?= e(admin_trans('user_first_name')) ?>:
            <input type="text" name="first_name" value="<?= e($user['first_name'] ?? '') ?>">
        </label>

        <label>
            <?= e(admin_trans('user_last_name')) ?>:
            <input type="text" name="last_name" value="<?= e($user['last_name'] ?? '') ?>">
        </label>

        <label>
            <?= e(admin_trans('user_email')) ?>:
            <input type="email" name="email" value="<?= e($user['email'] ?? '') ?>">
        </label>

        <?php if (admin_can('users.manage')): ?>
            <label>
                <?= e(admin_trans('user_role')) ?>:
                <select name="role">
                    <?php foreach (admin_roles() as $roleCode): ?>
                        <option value="<?= e($roleCode) ?>" <?= (($user['role'] ?? 'author') === $roleCode) ? 'selected' : '' ?>>
                            <?= e(admin_role_label($roleCode)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small><?= e(admin_trans('user_help_role')) ?></small>
            </label>
        <?php endif; ?>

        <label>
            <?= e(admin_trans('user_language')) ?>:
            <select name="ui_language">
                <option value=""><?= e(admin_trans('user_language_default')) ?></option>
                <?php foreach ($adminLanguages as $languageCode => $languageLabel): ?>
                    <option value="<?= e($languageCode) ?>" <?= (($user['ui_language'] ?? '') === $languageCode) ? 'selected' : '' ?>>
                        <?= e($languageLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small><?= e(admin_trans('user_language_help')) ?></small>
        </label>
    </fieldset>

    <fieldset>
        <legend><?= e(admin_trans('user_password_update')) ?></legend>

        <label>
            <?= e(admin_trans('user_password')) ?>:
            <input type="password" name="password" placeholder="<?= e(admin_trans('user_password_keep')) ?>">
        </label>

        <label>
            <?= e(admin_trans('user_confirm_password')) ?>:
            <input type="password" name="password_confirm" placeholder="<?= e(admin_trans('user_password_keep')) ?>">
        </label>
    </fieldset>
</form>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('user_edit')) ?></h3>
<p><?= e(admin_trans('user_help_form')) ?></p>
<p><?= e(admin_trans('user_help_password')) ?></p>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'roles'];

include CMS_PATH . '/admin/partials/layout.php';
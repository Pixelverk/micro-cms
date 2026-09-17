<?php

$username = current_username();
$editUsername = $_GET['username'] ?? '';

if (!$editUsername || !user_exists($editUsername)) {
    redirect_with_toast('user', 'error', 'User not found');
}

$pageTitle = 'Edit User: ' . $editUsername;

// Load user data
$users = load_users();
$user = $users[$editUsername] ?? [];
$adminLanguages = admin_languages();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p><?= e(admin_trans('editing_user', ['name' => $editUsername])) ?></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="edit-user"><?= e(admin_trans('save_changes')) ?></button>
    </div>
</div>

<form id="edit-user" method="post" action="<?= url('admin/user/save') ?>" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="original_username" value="<?= e($editUsername) ?>">

    <fieldset>
        <legend><?= e(admin_trans('user_info')) ?></legend>

        <label>
            <?= e(admin_trans('username')) ?>:
            <input type="text" name="username" value="<?= e($user['username'] ?? '') ?>" readonly>
            <small><?= e(admin_trans('username_cannot_change')) ?></small>
        </label>

        <label>
            <?= e(admin_trans('first_name')) ?>:
            <input type="text" name="first_name" value="<?= e($user['first_name'] ?? '') ?>">
        </label>

        <label>
            <?= e(admin_trans('last_name')) ?>:
            <input type="text" name="last_name" value="<?= e($user['last_name'] ?? '') ?>">
        </label>

        <label>
            <?= e(admin_trans('email')) ?>:
            <input type="email" name="email" value="<?= e($user['email'] ?? '') ?>">
        </label>

        <label>
            <?= e(admin_trans('role')) ?>:
            <select name="role">
                <?php foreach (admin_roles() as $roleCode): ?>
                    <option value="<?= e($roleCode) ?>" <?= (($user['role'] ?? 'author') === $roleCode) ? 'selected' : '' ?>>
                        <?= e(admin_role_label($roleCode)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small><?= e(admin_trans('role_help')) ?></small>
        </label>

        <label>
            <?= e(admin_trans('ui_language')) ?>:
            <select name="ui_language">
                <option value=""><?= e(admin_trans('use_default_admin_language')) ?></option>
                <?php foreach ($adminLanguages as $languageCode => $languageLabel): ?>
                    <option value="<?= e($languageCode) ?>" <?= (($user['ui_language'] ?? '') === $languageCode) ? 'selected' : '' ?>>
                        <?= e($languageLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small><?= e(admin_trans('personal_language_help')) ?></small>
        </label>
    </fieldset>

    <fieldset>
        <legend><?= e(admin_trans('update_password')) ?></legend>

        <label>
            <?= e(admin_trans('password')) ?>:
            <input type="password" name="password" placeholder="<?= e(admin_trans('leave_blank_password')) ?>">
        </label>

        <label>
            <?= e(admin_trans('confirm_password')) ?>:
            <input type="password" name="password_confirm" placeholder="<?= e(admin_trans('leave_blank_password')) ?>">
        </label>
    </fieldset>
</form>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('edit_user')) ?></h3>
<p><?= e(admin_trans('user_form_help')) ?></p>
<p><?= e(admin_trans('password_help')) ?></p>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'roles'];

include CMS_PATH . '/admin/partials/layout.php';
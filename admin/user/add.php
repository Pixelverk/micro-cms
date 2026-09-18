<?php

$pageTitle = admin_trans('user_add_title');
$username = current_username();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('common_hello', ['name' => $username])) ?></h2>
        <p><?= e(admin_trans('user_create')) ?></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="create-user"><?= e(admin_trans('user_create')) ?></button>
    </div>
</div>

<form id="create-user" method="post" action="<?= url('admin/user/save') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">

    <fieldset class="settings-group">
        <legend>
            <?= icon('profile-circle', 18) ?>
            <?= e(admin_trans('user_details')) ?>
        </legend>

        <div class="field-grid card">
            <div class="field">
                <label class="field-label" for="new-user-username"><?= e(admin_trans('user_username')) ?></label>
                <input class="field-input" type="text" id="new-user-username" name="username" required autocomplete="off" placeholder="<?= e(admin_trans('user_username_placeholder')) ?>">
            </div>

            <div class="field">
                <label class="field-label" for="new-user-role"><?= e(admin_trans('user_role')) ?></label>
                <select class="field-input" id="new-user-role" name="role">
                    <?php foreach (admin_roles() as $roleCode): ?>
                        <option value="<?= e($roleCode) ?>" <?= $roleCode === 'author' ? 'selected' : '' ?>>
                            <?= e(admin_role_label($roleCode)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small><?= e(admin_trans('user_help_role')) ?></small>
            </div>

            <div class="field">
                <label class="field-label" for="new-user-first-name"><?= e(admin_trans('user_first_name')) ?></label>
                <input class="field-input" type="text" id="new-user-first-name" name="first_name" placeholder="<?= e(admin_trans('common_optional')) ?>">
            </div>

            <div class="field">
                <label class="field-label" for="new-user-last-name"><?= e(admin_trans('user_last_name')) ?></label>
                <input class="field-input" type="text" id="new-user-last-name" name="last_name" placeholder="<?= e(admin_trans('common_optional')) ?>">
            </div>

            <div class="field">
                <label class="field-label" for="new-user-email"><?= e(admin_trans('user_email')) ?></label>
                <input class="field-input" type="email" id="new-user-email" name="email" placeholder="<?= e(admin_trans('common_optional')) ?>">
            </div>

        </div>
    </fieldset>

    <fieldset class="settings-group">
        <legend>
            <?= icon('settings', 18) ?>
            <?= e(admin_trans('user_password')) ?>
        </legend>

        <div class="field-grid card">
            <div class="field">
                <label class="field-label" for="new-user-password"><?= e(admin_trans('user_password')) ?></label>
                <input class="field-input" type="password" id="new-user-password" name="password" required placeholder="<?= e(admin_trans('user_password_placeholder')) ?>">
            </div>

            <div class="field">
                <label class="field-label" for="new-user-password-confirm"><?= e(admin_trans('user_confirm_password')) ?></label>
                <input class="field-input" type="password" id="new-user-password-confirm" name="password_confirm" required placeholder="<?= e(admin_trans('user_confirm_password_placeholder')) ?>">
            </div>
        </div>
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
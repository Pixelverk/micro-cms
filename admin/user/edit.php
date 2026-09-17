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
$passwordMinLength = (int) config('security.password_min_length', 10);
$isSelf = $editUsername === $username;

// The person is named in the heading, so the page leads with who is being
// edited rather than repeating the signed-in user from the top bar.
$displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($displayName === '') {
    $displayName = (string) ($user['username'] ?? $editUsername);
}

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e($displayName) ?></h2>
        <p><?= e(admin_trans('user_editing', ['name' => $editUsername])) ?></p>
    </div>
    <div class="page-actions">
        <?php if (!$isSelf): ?>
            <form method="post"
                action="<?= url('admin/user/remove') ?>"
                class="js-confirm-form"
                data-confirm="<?= e(admin_trans('user_delete_confirm', ['name' => $editUsername])) ?>"
                data-confirm-title="<?= e(admin_trans('user_delete')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="username" value="<?= e($editUsername) ?>">
                <button type="submit" class="btn-secondary"><?= e(admin_trans('user_delete')) ?></button>
            </form>
        <?php endif; ?>
        <button type="submit" form="edit-user"><?= e(admin_trans('common_save')) ?></button>
    </div>
</div>

<div class="page-sections">
<form id="edit-user" method="post" action="<?= url('admin/user/save') ?>" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="original_username" value="<?= e($editUsername) ?>">

    <fieldset class="settings-group">
        <legend>
            <?= icon('profile-circle', 18) ?>
            <?= e(admin_trans('user_info')) ?>
        </legend>

        <div class="field-grid">
            <div class="field">
                <label class="field-label" for="user-username"><?= e(admin_trans('user_username')) ?></label>
                <input class="field-input" type="text" id="user-username" name="username" value="<?= e($user['username'] ?? '') ?>" readonly>
                <small><?= e(admin_trans('user_username_fixed')) ?></small>
            </div>

            <?php if (admin_can('users.manage')): ?>
                <div class="field">
                    <label class="field-label" for="user-role"><?= e(admin_trans('user_role')) ?></label>
                    <select class="field-input" id="user-role" name="role">
                        <?php foreach (admin_roles() as $roleCode): ?>
                            <option value="<?= e($roleCode) ?>" <?= (($user['role'] ?? 'author') === $roleCode) ? 'selected' : '' ?>>
                                <?= e(admin_role_label($roleCode)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small><?= e(admin_trans('user_help_role')) ?></small>
                </div>
            <?php endif; ?>

            <div class="field">
                <label class="field-label" for="user-first-name"><?= e(admin_trans('user_first_name')) ?></label>
                <input class="field-input" type="text" id="user-first-name" name="first_name" value="<?= e($user['first_name'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="field-label" for="user-last-name"><?= e(admin_trans('user_last_name')) ?></label>
                <input class="field-input" type="text" id="user-last-name" name="last_name" value="<?= e($user['last_name'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="field-label" for="user-email"><?= e(admin_trans('user_email')) ?></label>
                <input class="field-input" type="email" id="user-email" name="email" value="<?= e($user['email'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="field-label" for="user-language"><?= e(admin_trans('user_language')) ?></label>
                <select class="field-input" id="user-language" name="ui_language">
                    <option value=""><?= e(admin_trans('user_language_default')) ?></option>
                    <?php foreach ($adminLanguages as $languageCode => $languageLabel): ?>
                        <option value="<?= e($languageCode) ?>" <?= (($user['ui_language'] ?? '') === $languageCode) ? 'selected' : '' ?>>
                            <?= e($languageLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small><?= e(admin_trans('user_language_help')) ?></small>
            </div>
        </div>
    </fieldset>

    <fieldset class="settings-group">
        <legend>
            <?= icon('settings', 18) ?>
            <?= e(admin_trans('user_password_update')) ?>
        </legend>

        <div class="field-grid">
            <div class="field">
                <label class="field-label" for="user-password"><?= e(admin_trans('user_password')) ?></label>
                <input class="field-input" type="password" id="user-password" name="password" autocomplete="new-password" placeholder="<?= e(admin_trans('user_password_keep')) ?>">
            </div>

            <div class="field">
                <label class="field-label" for="user-password-confirm"><?= e(admin_trans('user_confirm_password')) ?></label>
                <input class="field-input" type="password" id="user-password-confirm" name="password_confirm" autocomplete="new-password" placeholder="<?= e(admin_trans('user_password_keep')) ?>">
            </div>

            <p class="field-note field-span"><?= e(admin_trans('user_password_hint', ['min' => $passwordMinLength])) ?></p>
        </div>
    </fieldset>
</form>

<div class="card">
    <h2 class="card-title"><?= e(admin_trans('user_account_meta')) ?></h2>
    <dl class="meta-list">
        <dt><?= e(admin_trans('common_created')) ?></dt>
        <dd><?= isset($user['created_at']) ? e(date('Y-m-d H:i', (int) $user['created_at'])) : '—' ?></dd>

        <dt><?= e(admin_trans('user_last_login')) ?></dt>
        <dd><?= isset($user['last_login']) ? e(date('Y-m-d H:i', (int) $user['last_login'])) : '—' ?></dd>
    </dl>
</div>
</div>

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
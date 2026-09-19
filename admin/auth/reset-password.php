<?php

/*
|--------------------------------------------------------------------------
| Reset password
|--------------------------------------------------------------------------
|
| Public page reached from the emailed link. The raw token is looked up by
| hash, and a valid one must be present on both the GET that shows the form
| and the POST that submits it. CSRF is enforced centrally for admin POSTs
| (core/bootstrap/admin.php).
|
*/

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$reset = password_reset_find($token);

$errors = [];
$passwordMinLength = (int) config('security.password_min_length', 10);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($password === '') {
        $errors['password'] = admin_trans('user_error_password_required');
    } elseif (strlen($password) < $passwordMinLength) {
        $errors['password'] = admin_trans('user_error_password_length', ['min' => $passwordMinLength]);
    } elseif ($password !== $confirm) {
        $errors['password_confirm'] = admin_trans('user_error_password_match');
    }

    if (!$errors) {
        password_reset_complete($reset, $password);

        // The link is spent now; a fresh session is the only way forward.
        redirect_with_toast('login', 'success', admin_trans('auth_reset_success'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(admin_locale()) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= e(admin_trans('auth_reset_title')) ?> - Micro CMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= admin_asset('admin/assets/style.css') ?>">
    <link rel='icon' href="<?= admin_asset('admin/assets/favicon.png')?>">
</head>
<body class="auth-page">
<header class="auth-header">
    <div class="auth-brand">
        <?= icon('profile-circle', 20) ?>
        <h1><?= e(admin_trans('auth_title')) ?></h1>
    </div>
</header>
<main>
    <div class="login-card">
        <h2><?= e(admin_trans('auth_reset_title')) ?></h2>

        <?php if (!$reset): ?>
            <div class="notice notice-error"><?= e(admin_trans('auth_reset_invalid')) ?></div>

            <p class="field-note">
                <a href="<?= e(url('admin/forgot-password')) ?>"><?= e(admin_trans('auth_reset_request_new')) ?></a>
            </p>
        <?php else: ?>
            <?php if ($errors): ?>
                <div class="notice notice-error"><?= e(implode(' ', $errors)) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= url('admin/reset-password') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div class="field">
                    <label class="field-label" for="password"><?= e(admin_trans('auth_new_password')) ?></label>
                    <input class="field-input" type="password" id="password" name="password" required autofocus autocomplete="new-password">
                </div>

                <div class="field">
                    <label class="field-label" for="password-confirm"><?= e(admin_trans('auth_confirm_password')) ?></label>
                    <input class="field-input" type="password" id="password-confirm" name="password_confirm" required autocomplete="new-password">
                </div>

                <p class="field-note"><?= e(admin_trans('user_password_hint', ['min' => $passwordMinLength])) ?></p>

                <button type="submit" class="btn-primary"><?= e(admin_trans('auth_reset_button')) ?></button>
            </form>
        <?php endif; ?>

        <p class="field-note"><a href="<?= e(url('admin/login')) ?>"><?= e(admin_trans('auth_back_to_login')) ?></a></p>
    </div>
</main>
<?php include CMS_PATH . '/admin/partials/toasts.php'; ?>
</body>
</html>

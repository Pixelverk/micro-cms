<?php

/*
|--------------------------------------------------------------------------
| Forgot password
|--------------------------------------------------------------------------
|
| Public page: asks for the account email and shows the same confirmation
| whether or not it matches. CSRF is enforced centrally for admin POSTs
| (core/bootstrap/admin.php), and password_reset_rate_limit_ok() counts every
| attempt so the limit itself cannot reveal whether an address exists.
|
*/

$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    if (!password_reset_rate_limit_ok()) {
        $error = admin_trans('auth_forgot_rate_limited');
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = admin_trans('auth_forgot_error_email');
    } else {
        password_reset_request($email);
        $notice = admin_trans('auth_forgot_sent');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(admin_locale()) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= e(admin_trans('auth_forgot_title')) ?> - Micro CMS</title>
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
        <h2><?= e(admin_trans('auth_forgot_title')) ?></h2>

        <?php if ($error): ?>
            <div class="notice notice-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($notice): ?>
            <div class="notice notice-success"><?= e($notice) ?></div>
        <?php else: ?>
            <p class="field-note"><?= e(admin_trans('auth_forgot_help')) ?></p>

            <form method="post" action="<?= url('admin/forgot-password') ?>">
                <?= csrf_field() ?>

                <div class="field">
                    <label class="field-label" for="email"><?= e(admin_trans('auth_email')) ?></label>
                    <input class="field-input" type="email" id="email" name="email" required autofocus autocomplete="email">
                </div>

                <button type="submit" class="btn-primary"><?= e(admin_trans('auth_send_reset_link')) ?></button>
            </form>
        <?php endif; ?>

        <p class="field-note"><a href="<?= e(url('admin/login')) ?>"><?= e(admin_trans('auth_back_to_login')) ?></a></p>
    </div>
</main>
<?php include CMS_PATH . '/admin/partials/toasts.php'; ?>
</body>
</html>

<?php

// Handle form submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_assert();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = admin_trans('auth_error_missing');
    } elseif (throttle_is_locked($username)) {
        $minutes = (int) ceil(throttle_seconds_remaining($username) / 60);
        $error = admin_trans('auth_error_locked', ['minutes' => $minutes]);
        log_activity('user.login_locked', 'user', null, $username, []);
    } elseif (login($username, $password)) {
        // Opportunistic cleanup on a small share of successful logins.
        if (random_int(1, 100) === 1) {
            throttle_prune();
            activity_maybe_prune();
        }

        redirect_with_toast('dashboard', 'success', admin_trans('auth_success'));
        exit;
    } else {
        $error = admin_trans('auth_error_invalid');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(admin_locale()) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= e(admin_trans('auth_title')) ?> - Micro CMS</title>
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
        <h2><?= e(admin_trans('auth_login')) ?></h2>

        <?php if ($error): ?>
            <div class="notice notice-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= url('admin/login') ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label class="field-label" for="username"><?= e(admin_trans('auth_username')) ?></label>
                <input class="field-input" type="text" id="username" name="username" required autofocus autocomplete="username">
            </div>

            <div class="field">
                <label class="field-label" for="password"><?= e(admin_trans('auth_password')) ?></label>
                <input class="field-input" type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn-primary"><?= e(admin_trans('auth_log_in')) ?></button>
        </form>
    </div>
</main>
<?php include CMS_PATH . '/admin/partials/toasts.php'; ?>
</body>
</html>
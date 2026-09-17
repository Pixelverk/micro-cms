<?php

// Handle form submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_assert();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } elseif (throttle_is_locked($username)) {
        $minutes = (int) ceil(throttle_seconds_remaining($username) / 60);
        $error = "Too many failed attempts. Try again in about {$minutes} minute(s).";
        log_activity('user.login_locked', 'user', null, $username, []);
    } elseif (login($username, $password)) {
        // Opportunistic cleanup on a small share of successful logins.
        if (random_int(1, 100) === 1) {
            throttle_prune();
            activity_maybe_prune();
        }

        redirect_with_toast('dashboard', 'success', 'Login success');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Editor Login - Micro CMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= admin_asset('admin/assets/style.css') ?>">
    <link rel='icon' href="<?= admin_asset('admin/assets/favicon.png')?>">
</head>
<body class="auth-page">
<header>
    <h1>Micro CMS - Editor Login</h1>
</header>
<main>
    <div class="login-card">
        <h2>Login</h2>
        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= url('admin/login') ?>">
            <?= csrf_field() ?>
            <input type="text" name="username" placeholder="Username" required autofocus>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Log in</button>
        </form>
    </div>
</main>
<?php include CMS_PATH . '/admin/partials/toasts.php'; ?>
</body>
</html>
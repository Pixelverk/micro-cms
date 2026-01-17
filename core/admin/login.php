<?php

// Handle form submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (login($username, $password)) {
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
    <link rel="stylesheet" href="<?= url('core/admin/assets/style.css') ?>">
    <style>
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        main {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            background: #f7f7f7;
        }
        .login-card {
            background: #fff;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-width: 400px;
            width: 100%;
        }
        .login-card h2 { margin-top:0; margin-bottom:1rem; text-align:center; }
        .login-card form { display:flex; flex-direction:column; }
        .login-card input {
            padding:0.75rem;
            margin-bottom:1rem;
            border:1px solid #ccc;
            border-radius:4px;
            font-size:1rem;
        }
        .login-card button {
            padding:0.75rem;
            background:#1f2933;
            color:#fff;
            border:none;
            border-radius:4px;
            font-size:1rem;
            cursor:pointer;
        }
        .error { color:red; text-align:center; margin-bottom:1rem; }
    </style>
</head>
<body>
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
            <input type="text" name="username" placeholder="Username" required autofocus>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Log in</button>
        </form>
    </div>
</main>
<?php include __DIR__ . '/partials/toasts.php'; ?>
</body>
</html>
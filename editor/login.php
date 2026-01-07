<?php
session_start();

// If already logged in, redirect to index
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: index.php");
    exit;
}

// Optional error message
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Editor Login</title>
</head>
<body>
    <h1>Editor Login</h1>
    <?php if ($error): ?>
        <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    <form action="auth.php" method="post">
        <label>
            Username:
            <input type="text" name="username" required>
        </label><br><br>
        <label>
            Password:
            <input type="password" name="password" required>
        </label><br><br>
        <button type="submit">Login</button>
    </form>
</body>
</html>

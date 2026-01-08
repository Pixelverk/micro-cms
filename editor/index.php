<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_login();

$username = $_SESSION['user_id'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Editor - Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="_assets/style.css">
</head>
<body>

<header>
    <h1>Micro CMS - Dashboard</h1>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="page-list.php">Pages</a>
        <a href="user-list.php">Users</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main>
    <h2>Welcome, <?php echo htmlspecialchars($username); ?> 👋</h2>
    <p>Use the tools below to manage your site.</p>

    <div class="cards">
        <div class="card">
            <h2>Pages</h2>
            <p>View, edit, add, or remove pages.</p>
            <a href="page-list.php">Manage pages →</a>
        </div>

        <div class="card">
            <h2>Users</h2>
            <p>Create and manage editor accounts.</p>
            <a href="user-list.php">Manage users →</a>
        </div>

        <div class="card">
            <h2>Site Preview</h2>
            <p>Open the public site in a new tab.</p>
            <a href="/" target="_blank">View site →</a>
        </div>
    </div>
</main>

</body>
</html>
<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

$username = $_SESSION['user_id'] ?? 'User';
$pages = list_pages();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Editor - Pages</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="_assets/style.css">
</head>
<body>

<header>
    <h1>Micro CMS - Page List</h1>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="page-list.php">Pages</a>
        <a href="user-list.php">Users</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main>
    <h2>Hello, <?= htmlspecialchars($username) ?> 👋</h2>
    <p>Manage your pages below.</p>

    <div class="cards">
        <?php if (empty($pages)): ?>
            <div class="card">
                <h2>No pages found</h2>
                <p>Create your first page from the dashboard.</p>
                <a href="index.php">Go to Dashboard →</a>
            </div>
        <?php else: ?>
            <?php foreach ($pages as $page): ?>
                <div class="card">
                    <h2><?= html_entity_decode($page['title']) ?></h2>
                    <p>Slug: <?= htmlspecialchars($page['slug']) ?></p>
                    <a href="page-view.php?slug=<?= htmlspecialchars($page['slug']) ?>">Edit page →</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Optional: Add a “New Page” card -->
        <div class="card new-page">
            <h2>Create New Page</h2>
            <p>Add a brand new page to your site.</p>
            <a href="page-add.php">Add page →</a>
        </div>
    </div>
</main>

</body>
</html>
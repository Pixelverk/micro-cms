<?php

$pageTitle = 'Utilities';
$username = $_SESSION['user_id'] ?? 'User';

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p>Nothing to see here yet!</p>
    </div>
    <div class="page-actions">

    </div>
</div>

<?php
$content = ob_get_clean();

include __DIR__ . '/partials/layout.php';
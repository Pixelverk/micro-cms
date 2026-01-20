<?php
$pageTitle = $pageTitle ?? 'Micro CMS Editor';
?>

<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e($pageTitle) ?> - Micro CMS</title>
        <link rel="stylesheet" href="<?= url('admin/assets/style.css')?>">
    </head>

    <body>

        <div class="admin-layout">
            <?php include __DIR__ . '/sidebar.php'; ?>

            <main>
                <?php include __DIR__ . '/header.php'; ?>

                <div class="main-content">
                    <?= $content ?? '' ?>
                </div>

            </main>

            <?php include __DIR__ . '/toasts.php'; ?>
        </div>   

    </body>

</html>
<?php
$pageTitle = $pageTitle ?? 'Micro CMS Editor';

// Pages that need extra assets declare them before rendering their content:
//   $pageStyles[] = ['href' => '...'];            (loaded in <head>)
//   $pageScripts[] = ['src' => '...', 'defer' => false];  (loaded before </body>)
$pageStyles  = $pageStyles ?? [];
$pageScripts = $pageScripts ?? [];
?>

<!DOCTYPE html>
<html lang="<?= e(admin_locale()) ?>">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e($pageTitle) ?> - Micro CMS</title>
        <link rel="stylesheet" href="<?= admin_asset('admin/assets/style.css')?>">
        <link rel='icon' href="<?= admin_asset('admin/assets/favicon.png')?>">

        <?php foreach ($pageStyles as $style): ?>
            <link rel="stylesheet" href="<?= e(admin_asset($style['href'])) ?>">
        <?php endforeach; ?>

        <script src="<?= admin_asset('admin/assets/main.js')?>" defer></script>
        <script>
            window.adminTranslations = <?php echo json_encode(
                require CMS_PATH . '/admin/lang/' . admin_locale() . '.php',
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) ?>;
        </script>
    </head>

    <body class="no-transitions">

        <a class="skip-link" href="#main-content"><?= e(admin_trans('nav_skip_to_content')) ?></a>

        <div class="admin-layout">
            <?php include __DIR__ . '/sidebar.php'; ?>

            <main id="main-content">
                <?php include __DIR__ . '/header.php'; ?>

                <div class="main-container">
                    <?= $content ?? '' ?>
                </div>

            </main>

            <?php include __DIR__ . '/toasts.php'; ?>
            <?php include __DIR__ . '/confirm.php'; ?>
        </div>

        <?php foreach ($pageScripts as $script): ?>
            <script src="<?= e(admin_asset($script['src'])) ?>"<?= !empty($script['defer']) ? ' defer' : '' ?>></script>
        <?php endforeach; ?>

    </body>

</html>

<?php include __DIR__ . '/head.php'; ?>
<?php include __DIR__ . '/header.php'; ?>

<div class="admin-layout">
    <aside>
        <?php include __DIR__ . '/sidebar.php'; ?>
    </aside>
    <main>
        <?= $content ?? '' ?>
    </main>
</div>

<?php include __DIR__ . '/help.php'; ?>
<?php include __DIR__ . '/toasts.php'; ?>
<?php include __DIR__ . '/footer.php'; ?>
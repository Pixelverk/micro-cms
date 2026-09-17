<?php if (empty($pageHelp)) return; ?>

<span id="help-fab" class="header-icon"><?= icon('help-circle', 20) ?></span>

<div id="help-panel">
    <?= $pageHelp ?>

    <?php
    // Every help panel links into the full documentation. Pages can point at a
    // specific section with $docsLink = ['tab' => 'editor', 'section' => 'slug'].
    $docsLink = $docsLink ?? ['tab' => 'editor'];
    $docsQuery = http_build_query(array_filter($docsLink));
    ?>
    <p class="help-docs-link">
        <a href="<?= e(url('admin/docs') . ($docsQuery !== '' ? '?' . $docsQuery : '')) ?>">
            <?= e(admin_trans('help_read_docs')) ?> &rarr;
        </a>
    </p>
</div>

<script>
(() => {
    const fab = document.getElementById('help-fab');
    const panel = document.getElementById('help-panel');

    if (!fab || !panel) return;

    fab.addEventListener('click', () => {
        panel.style.display =
            panel.style.display === 'block' ? 'none' : 'block';
    });

    document.addEventListener('click', (e) => {
        if (!panel.contains(e.target) && !fab.contains(e.target)) {
            panel.style.display = 'none';
        }
    });
})();
</script>
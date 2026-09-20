<?php if (empty($pageHelp)) return; ?>

<button type="button" id="help-fab" class="header-icon" aria-label="<?= e(admin_trans('nav_aria_help')) ?>" aria-expanded="false" aria-controls="help-panel"><?= icon('help-circle', 20) ?></button>

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

    const setOpen = open => {
        panel.style.display = open ? 'block' : 'none';
        fab.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    fab.addEventListener('click', () => {
        setOpen(panel.style.display !== 'block');
    });

    document.addEventListener('click', (e) => {
        if (!panel.contains(e.target) && !fab.contains(e.target)) {
            setOpen(false);
        }
    });
})();
</script>
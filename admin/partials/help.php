<?php if (empty($pageHelp)) return; ?>

<style>
#help-fab {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    color: #2563eb;
    background: #fff;
    padding:0;
    border: 1px solid;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,.25);
    z-index: 9998;
}

#help-panel {
    position: fixed;
    top: 6.5rem;
    right: 2rem;
    margin-left:2rem;
    width: fit-content;
    max-width: calc(100vw - 3rem);
    background: #fff;
    color: #222;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    padding: 1rem 1.25rem;
    display: none;
    z-index: 9999;
}

#help-panel h3 {
    margin-top: 0.5rem;
}
</style>

<button id="help-fab" type="button" aria-label="Page help">?</button>

<div id="help-panel">
    <?= $pageHelp ?>
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
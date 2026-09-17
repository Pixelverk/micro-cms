<?php if (!empty($_SESSION['toast'])): ?>
<div id="toast-container"></div>

<script>
function showToast(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('hide');
        toast.addEventListener('animationend', () => toast.remove());
    }, duration);
}

document.addEventListener('DOMContentLoaded', () => {
    showToast(
        <?= json_encode($_SESSION['toast']['message'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        <?= json_encode($_SESSION['toast']['type']) ?>
    );
});
</script>
<?php unset($_SESSION['toast']); endif; ?>
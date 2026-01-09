<style>
#toast-container {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    z-index: 9999;
}

.toast {
    background: #333;
    color: #fff;
    padding: 0.75rem 1.25rem;
    border-radius: 6px;
    margin-top: 0.5rem;
    opacity: 0;
    animation: fadeIn 0.3s forwards;
    box-shadow: 0 4px 10px rgba(0,0,0,.2);
}

.toast.success { background: #2e7d32; }
.toast.error { background: #c62828; }
.toast.info { background: #1565c0; }

.toast.hide {
    animation: fadeOut 0.4s forwards;
}

@keyframes fadeIn {
    to { opacity: 1; }
}

@keyframes fadeOut {
    to { opacity: 0; }
}
</style>
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
</script>

<?php if (!empty($_SESSION['toast'])): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    showToast(
        <?= json_encode($_SESSION['toast']['message']) ?>,
        <?= json_encode($_SESSION['toast']['type']) ?>
    );
});
</script>
<?php unset($_SESSION['toast']); endif; ?>
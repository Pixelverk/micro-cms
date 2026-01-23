
/* Full screen switch */
const expandBtn   = document.getElementById('full-screen-expand');
const collapseBtn = document.getElementById('full-screen-collapse');

// Toggle fullscreen
function toggleFullscreen() {
    document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen();
}

// Update UI + state
function syncUI() {
    const active = !!document.fullscreenElement;

    expandBtn.style.display = active ? 'none' : 'block';
    collapseBtn.style.display = active ? 'block' : 'none';

    localStorage.setItem('inFullScreen', active ? '1' : '0');
}

// Restore previous state
if (localStorage.getItem('inFullScreen') === '1') {
    document.documentElement.requestFullscreen().catch(() => {});
}

// Events
expandBtn.onclick = toggleFullscreen;
collapseBtn.onclick = toggleFullscreen;
document.addEventListener('fullscreenchange', syncUI);

// Initial state
syncUI();
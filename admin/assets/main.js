
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

/* Light & dark mode switch */

const lightBtn = document.getElementById('light-mode');
const darkBtn  = document.getElementById('dark-mode');

const STORAGE_KEY = 'adminColorMode';
const mediaQuery  = window.matchMedia('(prefers-color-scheme: dark)');
const savedTheme  = localStorage.getItem(STORAGE_KEY);

function applyTheme(theme, persist = true) {
    const isDark = theme === 'dark';

    document.documentElement.classList.toggle('dark', isDark);

    darkBtn.style.display  = isDark ? 'none' : 'block';
    lightBtn.style.display = isDark ? 'block' : 'none';

    if (persist) {
        localStorage.setItem(STORAGE_KEY, theme);
    }
}

// ----------------------------
// Initial load
// ----------------------------
if (savedTheme) {
    // User explicitly chose → respect it
    applyTheme(savedTheme);
} else {
    // No choice yet → follow system
    applyTheme(mediaQuery.matches ? 'dark' : 'light', false);

    // React to OS changes only while user hasn't chosen
    mediaQuery.addEventListener('change', e => {
        applyTheme(e.matches ? 'dark' : 'light', false);
    });
}

// ----------------------------
// Click handlers (manual choice)
// ----------------------------
darkBtn.addEventListener('click', () => applyTheme('dark'));
lightBtn.addEventListener('click', () => applyTheme('light'));
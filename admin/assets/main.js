
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

    expandBtn.style.display = active ? 'none' : 'inline-flex';
    collapseBtn.style.display = active ? 'inline-flex' : 'none';

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

const COLOR_KEY = 'adminColorMode';
const mediaQuery  = window.matchMedia('(prefers-color-scheme: dark)');
const savedTheme  = localStorage.getItem(COLOR_KEY);

function applyTheme(theme, persist = true) {
    const isDark = theme === 'dark';

    document.documentElement.classList.toggle('dark', isDark);

    darkBtn.style.display  = isDark ? 'none' : 'inline-flex';
    lightBtn.style.display = isDark ? 'inline-flex' : 'none';

    if (persist) {
        localStorage.setItem(COLOR_KEY, theme);
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



/* sidebar switch */

const shrinkBtn = document.getElementById('sidebar-collapse');
const growBtn   = document.getElementById('sidebar-expand');

const SIDEBAR_KEY = 'adminSidebarCollapsed';

function setSidebar(collapsed) {
    document.body.classList.toggle('sidebar-collapsed', collapsed);

    shrinkBtn.style.display = collapsed ? 'none' : 'inline-flex';
    growBtn.style.display   = collapsed ? 'inline-flex' : 'none';

    localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
}

// Restore state
const saved = localStorage.getItem(SIDEBAR_KEY) === '1';
setSidebar(saved);

// Click handlers
shrinkBtn.addEventListener('click', () => setSidebar(true));
growBtn.addEventListener('click',   () => setSidebar(false));



/* only let things move after dom loaded and animation frames start */
requestAnimationFrame(() => {
    document.body.classList.remove('no-transitions');
});
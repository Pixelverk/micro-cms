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
const growBtn = document.getElementById('sidebar-expand');
const sideBarTitle = document.querySelector('.sidebar-header h1 a');

const SIDEBAR_KEY = 'adminSidebarCollapsed';

function setSidebar(collapsed) {
    document.body.classList.toggle('sidebar-collapsed', collapsed);

    if (shrinkBtn) shrinkBtn.style.display = collapsed ? 'none' : 'inline-flex';
    if (growBtn) growBtn.style.display = collapsed ? 'inline-flex' : 'none';
    if (sideBarTitle) sideBarTitle.textContent = collapsed ? 'CMS' : 'Micro CMS';

    localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
}

// Restore state
const saved = localStorage.getItem(SIDEBAR_KEY) === '1';
setSidebar(saved);

// Click handlers
shrinkBtn.addEventListener('click', () => setSidebar(true));
growBtn.addEventListener('click',   () => setSidebar(false));

/* mobile off-canvas navigation */

const mobileMenuBtn = document.getElementById('mobile-menu');

function setMobileNav(open) {
    document.body.classList.toggle('mobile-nav-open', open);
    mobileMenuBtn?.setAttribute('aria-expanded', open ? 'true' : 'false');
}

mobileMenuBtn?.addEventListener('click', () => {
    setMobileNav(!document.body.classList.contains('mobile-nav-open'));
});

// Tapping the backdrop or following a link closes the drawer.
document.addEventListener('click', event => {
    if (!document.body.classList.contains('mobile-nav-open')) return;

    if (event.target.closest('.sidebar a')) {
        setMobileNav(false);
        return;
    }

    if (!event.target.closest('.sidebar') && !event.target.closest('#mobile-menu')) {
        setMobileNav(false);
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') setMobileNav(false);
});

window.addEventListener('resize', () => {
    if (window.innerWidth > 900) setMobileNav(false);
});



/* only let things move after dom loaded and animation frames start */
requestAnimationFrame(() => {
    document.body.classList.remove('no-transitions');
});

/* confirm modal helper */

const modal = document.getElementById('confirm-modal');
const titleEl = document.getElementById('confirm-title');
const messageEl = document.getElementById('confirm-message');
const okBtn = document.getElementById('confirm-ok');
const cancelBtn = document.getElementById('confirm-cancel');

let onConfirm = null;

const adminText = (key, fallback) => window.adminTranslations?.[key] || fallback;

function confirmModal({ title = adminText('common_confirm', 'Confirm'), message = adminText('common_are_you_sure', 'Are you sure?'), simple = false } = {}) {
    titleEl.textContent = title;
    messageEl.textContent = message;

    modal.style.display = 'flex';

    if(simple){
        cancelBtn.style.display = 'none';
        okBtn.textContent = adminText('common_ok', 'OK');
    }

    return new Promise(resolve => {
        onConfirm = () => resolve(true);
        cancelBtn.onclick = () => resolve(false);
    });
}

okBtn.addEventListener('click', () => {
    modal.style.display = 'none';
    onConfirm?.();
});

cancelBtn.addEventListener('click', () => {
    modal.style.display = 'none';
});

/* Listen for clicks on confirm buttons */
document.addEventListener('click', async e => {
    const el = e.target.closest('.js-confirm');
    if (!el) return;

    e.preventDefault();

    const ok = await confirmModal({
        title: el.dataset.confirmTitle,
        message: el.dataset.confirm
    });

    if (ok) {
        window.location.href = el.href;
    }
});

/* the confirm modal works on forms too */
document.addEventListener('submit', async e => {
    const form = e.target.closest('.js-confirm-form');
    if (!form) return;

    e.preventDefault();

    const ok = await confirmModal({
        title: form.dataset.confirmTitle,
        message: form.dataset.confirm
    });

    if (ok) {
        form.submit();
    }
});

/* Auto-fill a slug field from a name field until the slug is edited by hand.
   The category and tag editors share this; the content editor has its own. */
const nameField = document.getElementById('name');
const slugField = document.getElementById('slug');

if (nameField && slugField) {
    let slugTouched = false;

    slugField.addEventListener('input', () => { slugTouched = true; });

    nameField.addEventListener('input', () => {
        if (!slugTouched) {
            slugField.value = nameField.value
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
    });
}
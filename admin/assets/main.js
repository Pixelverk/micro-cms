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

const sideBar = document.querySelector('.sidebar');

// The collapsed rail hides the labels, so a hovered link shows its label in a
// floating tooltip. It cannot be a child of the link: the rail scrolls, and a
// scroll container clips anything outside its own box.
const railTooltip = document.createElement('div');
railTooltip.className = 'sidebar-tooltip';
railTooltip.hidden = true;
document.body.appendChild(railTooltip);

function hideRailTooltip() {
    railTooltip.hidden = true;
}

function setSidebar(collapsed) {
    document.body.classList.toggle('sidebar-collapsed', collapsed);

    if (shrinkBtn) shrinkBtn.style.display = collapsed ? 'none' : 'inline-flex';
    if (growBtn) growBtn.style.display = collapsed ? 'inline-flex' : 'none';
    if (sideBarTitle) sideBarTitle.textContent = collapsed ? 'CMS' : 'Micro CMS';

    localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
    hideRailTooltip();
}

// Restore state
const saved = localStorage.getItem(SIDEBAR_KEY) === '1';
setSidebar(saved);

// Click handlers
shrinkBtn.addEventListener('click', () => setSidebar(true));
growBtn.addEventListener('click',   () => setSidebar(false));

// Show the hovered link's label while the rail is collapsed. On a phone the
// rail is not collapsed, so the labels are already visible.
if (sideBar) {
    sideBar.addEventListener('mouseover', event => {
        const link = event.target.closest('.sidebar-link');

        if (!link
            || !document.body.classList.contains('sidebar-collapsed')
            || window.matchMedia('(max-width: 900px)').matches) {
            hideRailTooltip();
            return;
        }

        const rect = link.getBoundingClientRect();

        railTooltip.textContent = link.dataset.label || '';
        railTooltip.style.top = `${rect.top + rect.height / 2}px`;
        railTooltip.hidden = false;
    });

    sideBar.addEventListener('mouseleave', hideRailTooltip);
}

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

/* dialog helper: one open/close path for every .modal-backdrop */

const dialogTriggers = new WeakMap();

/**
 * The controls Tab should cycle through inside an open dialog. Hidden controls
 * (a simple confirm's Cancel, say) have no client rects and drop out.
 */
function dialogElements(backdrop) {
    const dialog = backdrop.querySelector('.modal') || backdrop;

    return [...dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter(el => el.getClientRects().length > 0);
}

function openDialog(backdrop, trigger = null) {
    if (!backdrop) return;

    dialogTriggers.set(backdrop, trigger || document.activeElement);

    backdrop.hidden = false;
    backdrop.style.display = 'flex';

    const dialog = backdrop.querySelector('.modal') || backdrop;
    const focusable = dialogElements(backdrop);

    (focusable[0] || dialog).focus();
}

function closeDialog(backdrop) {
    if (!backdrop || backdrop.hidden) return;

    backdrop.hidden = true;
    backdrop.style.display = '';

    const trigger = dialogTriggers.get(backdrop);
    dialogTriggers.delete(backdrop);

    if (trigger && document.contains(trigger)) trigger.focus();

    // Lets an awaiting caller (the confirm promise) settle as cancelled.
    backdrop.dispatchEvent(new CustomEvent('dialog:closed'));
}

function activeDialog() {
    const open = [...document.querySelectorAll('.modal-backdrop')]
        .filter(backdrop => !backdrop.hidden && backdrop.style.display !== 'none');

    return open.length ? open[open.length - 1] : null;
}

/* Escape closes the top dialog; Tab stays inside it. */
document.addEventListener('keydown', event => {
    const backdrop = activeDialog();
    if (!backdrop) return;

    if (event.key === 'Escape') {
        event.preventDefault();
        closeDialog(backdrop);
        return;
    }

    if (event.key !== 'Tab') return;

    const focusable = dialogElements(backdrop);

    if (!focusable.length) {
        event.preventDefault();
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
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

    // Reset both ways: a simple dialog must not leave the next one without a
    // Cancel button or labelled OK.
    cancelBtn.style.display = simple ? 'none' : '';
    okBtn.textContent = simple ? adminText('common_ok', 'OK') : adminText('common_confirm', 'Confirm');

    openDialog(modal);

    return new Promise(resolve => {
        onConfirm = () => resolve(true);
        modal.addEventListener('dialog:closed', () => resolve(false), { once: true });
    });
}

okBtn.addEventListener('click', () => {
    onConfirm?.();
    onConfirm = null;
    closeDialog(modal);
});

cancelBtn.addEventListener('click', () => {
    closeDialog(modal);
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
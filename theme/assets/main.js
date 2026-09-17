document.addEventListener("DOMContentLoaded", () => {

    /**
     * Resolve target element from data attributes or href
     */
    function getTarget(trigger) {
        const selector = trigger.dataset.bsTarget ||
                         trigger.dataset.target ||
                         trigger.getAttribute('href') ||
                         (trigger.getAttribute('aria-controls') ? `#${trigger.getAttribute('aria-controls')}` : null);

        if (!selector || selector === '#' || selector.startsWith('#!')) return null;

        try {
            return document.querySelector(selector);
        } catch {
            return null;
        }
    }

    /**
     * Dropdowns: Close all open dropdown menus, optionally sparing an active branch
     */
    function closeAllDropdowns(exceptMenu = null) {
        document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
            if (exceptMenu && (menu === exceptMenu || menu.contains(exceptMenu))) {
                return;
            }
            menu.classList.remove('show');
            const toggle = menu.closest('.dropdown')?.querySelector(':scope > .dropdown-toggle');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        });
    }

    /**
     * Dropdowns: Handle click on a dropdown toggle
     */
    function handleDropdownClick(event, toggle) {
        event.preventDefault();
        event.stopPropagation();

        const parent = toggle.closest('.dropdown');
        const menu = parent ? parent.querySelector(':scope > .dropdown-menu') : null;
        if (!menu) return;

        const isCurrentlyOpen = menu.classList.contains('show');

        // Close unrelated open dropdowns (keeps parent open if this is a nested submenu)
        closeAllDropdowns(isCurrentlyOpen ? null : menu);

        if (isCurrentlyOpen) {
            menu.classList.remove('show');
            // Also close any child submenus
            menu.querySelectorAll('.dropdown-menu.show').forEach(child => {
                child.classList.remove('show');
                const childToggle = child.closest('.dropdown')?.querySelector(':scope > .dropdown-toggle');
                if (childToggle) childToggle.setAttribute('aria-expanded', 'false');
            });
            toggle.setAttribute('aria-expanded', 'false');
        } else {
            menu.classList.add('show');
            toggle.setAttribute('aria-expanded', 'true');
        }
    }

    /**
     * Collapse: Smoothly open a collapse panel
     */
    function openCollapsePanel(panel, button) {
        if (panel.classList.contains('collapsing') || panel.classList.contains('show')) return;

        if (button) {
            button.classList.remove('collapsed');
            button.setAttribute('aria-expanded', 'true');
        }

        // Instant toggle for reduced motion or non-accordion collapses (e.g. mobile navbar)
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !panel.classList.contains('accordion-collapse')) {
            panel.classList.add('show');
            return;
        }

        panel.classList.remove('collapse');
        panel.classList.add('collapsing');
        panel.style.height = '0px';

        panel.offsetHeight; // Force reflow

        let done = false;
        const onTransitionEnd = (e) => {
            if (e && e.target !== panel) return;
            if (done) return;
            done = true;
            panel.removeEventListener('transitionend', onTransitionEnd);
            panel.classList.remove('collapsing');
            panel.classList.add('collapse', 'show');
            panel.style.height = '';
        };

        panel.addEventListener('transitionend', onTransitionEnd);
        setTimeout(onTransitionEnd, 400);

        panel.style.height = panel.scrollHeight + 'px';
    }

    /**
     * Collapse: Smoothly close a collapse panel
     */
    function closeCollapsePanel(panel, accordion = null, button = null) {
        if (panel.classList.contains('collapsing') || !panel.classList.contains('show')) return;

        if (!button) {
            const selector = `[data-bs-target="#${panel.id}"], [data-target="#${panel.id}"], [href="#${panel.id}"], [aria-controls="${panel.id}"]`;
            button = (accordion || document).querySelector(selector);
        }

        if (button) {
            button.classList.add('collapsed');
            button.setAttribute('aria-expanded', 'false');
        }

        // Instant toggle for reduced motion or non-accordion collapses (e.g. mobile navbar)
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !panel.classList.contains('accordion-collapse')) {
            panel.classList.remove('show');
            return;
        }

        panel.style.height = panel.scrollHeight + 'px';
        panel.offsetHeight; // Force reflow

        panel.classList.add('collapsing');
        panel.classList.remove('collapse', 'show');
        panel.offsetHeight; // Force reflow to commit transition start

        let done = false;
        const onTransitionEnd = (e) => {
            if (e && e.target !== panel) return;
            if (done) return;
            done = true;
            panel.removeEventListener('transitionend', onTransitionEnd);
            panel.classList.remove('collapsing');
            panel.classList.add('collapse');
            panel.style.height = '';
        };

        panel.addEventListener('transitionend', onTransitionEnd);
        setTimeout(onTransitionEnd, 400);

        panel.style.height = '0px';
    }

    /**
     * Collapse: Toggle state (Accordion or Navbar)
     */
    function toggleCollapse(button) {
        const target = getTarget(button);
        if (!target) return;

        const accordion = button.closest('.accordion');
        const isOpening = !target.classList.contains('show');

        // In an accordion, closing siblings before opening the selected item
        if (accordion && isOpening) {
            accordion.querySelectorAll('.accordion-collapse.show, .collapse.show').forEach(openPanel => {
                if (openPanel !== target) {
                    closeCollapsePanel(openPanel, accordion);
                }
            });
        }

        if (isOpening) {
            openCollapsePanel(target, button);
        } else {
            closeCollapsePanel(target, accordion, button);
        }
    }

    /**
     * Global Event Delegation: Click
     */
    document.addEventListener('click', event => {
        // 1. Dropdown toggle
        const dropdownToggle = event.target.closest('.dropdown-toggle');
        if (dropdownToggle) {
            handleDropdownClick(event, dropdownToggle);
            return;
        }

        // 2. Clicking outside of any dropdown closes open dropdowns
        if (!event.target.closest('.dropdown')) {
            closeAllDropdowns();
        }

        // 3. Navbar toggler button (mobile menu)
        const navbarToggler = event.target.closest('.navbar-toggler');
        if (navbarToggler) {
            event.preventDefault();
            toggleCollapse(navbarToggler);
            return;
        }

        // 4. Accordion button or generic collapse trigger
        const collapseTrigger = event.target.closest('.accordion-button, [data-bs-toggle="collapse"], [data-toggle="collapse"]');
        if (collapseTrigger && !collapseTrigger.classList.contains('navbar-toggler')) {
            event.preventDefault();
            toggleCollapse(collapseTrigger);
            return;
        }

        // 5. Clicking a nav-link inside an open mobile navbar closes it
        const navLink = event.target.closest('.navbar-nav a');
        if (navLink && !navLink.classList.contains('dropdown-toggle')) {
            const openNav = document.querySelector('.navbar-collapse.show');
            if (openNav && window.innerWidth < 992) {
                const toggler = document.querySelector('.navbar-toggler');
                if (toggler) toggleCollapse(toggler);
            }
        }
    });

    /**
     * Global Event: Keyboard accessibility (Escape key)
     */
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            const openMenus = document.querySelectorAll('.dropdown-menu.show');
            if (openMenus.length > 0) {
                closeAllDropdowns();
                const activeToggle = document.activeElement?.closest('.dropdown')?.querySelector('.dropdown-toggle');
                if (activeToggle) activeToggle.focus();
            }

            const openNav = document.querySelector('.navbar-collapse.show');
            if (openNav && window.innerWidth < 992) {
                const toggler = document.querySelector('.navbar-toggler');
                if (toggler) {
                    toggleCollapse(toggler);
                    toggler.focus();
                }
            }
        }
    });

    /**
     * Media: Image LQIP blur removal and responsive container sizing
     */
    document.querySelectorAll('.image-wrapper picture img').forEach(img => {
        const wrapper = img.closest('.image-wrapper');
        if (!wrapper) return;

        // Remove LQIP blur when image loads
        const removeBlur = () => {
            wrapper.style.filter = 'none';
            wrapper.style.backgroundImage = 'none';
            img.classList.add('loaded');
        };

        if (!img.complete) {
            img.addEventListener('load', removeBlur);
        } else {
            removeBlur();
        }

        // Adjust sizes to actual container width after page load
        const resizeObserver = new ResizeObserver(entries => {
            for (let entry of entries) {
                const w = Math.ceil(entry.contentRect.width);
                if (w > 0) {
                    img.sizes = w + 'px';
                }
            }
        });

        resizeObserver.observe(wrapper);
    });

});
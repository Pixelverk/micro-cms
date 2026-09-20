// ----------------------------
// Menu Editor JS
// ----------------------------

const t = (key, fallback) => window.adminTranslations?.[key] || fallback;

const container = document.getElementById('menu-items-container');
const template = document.getElementById('menu-item-template');
const initialItems = window.initialMenuItems || [];
const linkOptions = window.menuLinkOptions || {};

const kindSelect = document.getElementById('new-item-kind');
const linkSelect = document.getElementById('new-item-link');
const addLinkButton = document.getElementById('add-link-item');

// ----------------------------
// Pick what to link to: a type first, then an item of that type
// ----------------------------
function loadLinkChoices() {
    const options = linkOptions[kindSelect.value] || [];

    linkSelect.innerHTML = '';
    linkSelect.appendChild(new Option(t('menu_select_content', 'Choose an item'), ''));

    options.forEach(item => {
        const option = new Option(`${item.label} — ${item.path}`, `${item.type}:${item.slug}`);

        option.dataset.type = item.type;
        option.dataset.id = item.id;
        option.dataset.slug = item.slug;
        option.dataset.label = item.label;
        option.dataset.url = item.path;

        linkSelect.appendChild(option);
    });

    linkSelect.disabled = options.length === 0;
    addLinkButton.disabled = true;
}

kindSelect.addEventListener('change', loadLinkChoices);
linkSelect.addEventListener('change', () => {
    addLinkButton.disabled = linkSelect.value === '';
});

// ----------------------------
// Create a menu item node
// ----------------------------
function createMenuItem(data = {}) {
    const node = template.content.firstElementChild.cloneNode(true);

    // Populate this row's own fields. The row's controls are queried with
    // :scope — a plain querySelector would search the children too, and the
    // hidden switch sits after the children container, so a parent row would
    // bind to its first child's controls instead of its own.
    node.querySelectorAll(':scope > .menu-fields .field-input, :scope > .menu-actions .field-input').forEach(input => {
        const key = input.dataset.field;
        if (!key) return;

        if (input.type === 'checkbox') {
            input.checked = !!data[key];
        } else {
            input.value = data[key] ?? '';
        }
    });

    // The resolved link travels with the row so duplicating an item keeps it.
    node.dataset.url = data.url || '';
    node.dataset.broken = data.broken ? '1' : '';

    applyMenuItemKind(node);

    // Update legend with label
    updateMenuItemLegend(node);

    // Children
    const childrenContainer = node.querySelector('.children-container');
    if (Array.isArray(data.children)) {
        data.children.forEach(child => {
            childrenContainer.appendChild(createMenuItem(child));
        });
    }

    // Update legend on input change
    node.querySelector(':scope > .menu-fields [data-field="label"]').addEventListener('input', () => {
        updateMenuItemLegend(node);
    });

    node.querySelector(':scope > .menu-actions [data-field="hidden"]').addEventListener('change', () => {
        refreshHiddenState();
    });

    // A cloned row brings its own children container, which needs to be
    // sortable too. Bound here rather than after insertion so a nested item
    // built by the loop above is already sortable when it lands.
    node.querySelectorAll('.children-container').forEach(bindSortableList);

    return node;
}

// ----------------------------
// Drag to reorder
// ----------------------------
// The same library the content editor uses, vendored locally. Drag only
// reorders within one list: nesting is done with the add-child button, so
// dragging an item over another must never move it in or out of a level.
// Each list therefore gets its own group name, which is how Sortable isolates
// them from one another.
const SORTABLE_GROUP = 'menu-items';

function bindSortableList(list) {
    if (!list || list._menuSortable) return;

    list._menuSortable = new Sortable(list, {
        group: { name: SORTABLE_GROUP, pull: false, put: false },
        // The whole row is the handle; anything you can click or type in is
        // filtered out, so a button press or a text selection never turns into a
        // reorder. Nesting stays on the child action: dragging cannot move an
        // item between levels because each list refuses other lists' items.
        filter: '.menu-fields, .menu-actions, input, select, textarea, button, a, label',
        animation: 150,
        ghostClass: 'sortable-ghost',
        fallbackOnBody: true,
        swapThreshold: 0.65,
        onEnd: () => {
            renumberMenuItems();
        },
    });
}

// ----------------------------
// Custom link or content link?
// ----------------------------
// A custom link is typed by hand; content and archive links resolve on the
// server, so their slug is kept for the fallback but never shown, and the row
// displays where the item points instead.
function applyMenuItemKind(node) {
    const isCustom = (node.querySelector(':scope > .menu-fields [data-field="type"]')?.value || 'url') === 'url';

    const input = node.querySelector(':scope > .menu-fields [data-field="slug"]');
    const value = node.querySelector(':scope > .menu-fields [data-link-value]');

    if (input) input.hidden = !isCustom;
    if (value) {
        value.hidden = isCustom;
        value.textContent = node.dataset.url || input?.value || '';
    }

    const warning = node.querySelector(':scope > [data-item-warning]');
    if (warning) {
        const broken = node.dataset.broken === '1';
        warning.hidden = !broken;
        warning.textContent = broken ? t('menu_link_broken', 'This link no longer resolves.') : '';
    }
}

// ----------------------------
// Update <legend> label
// ----------------------------
function updateMenuItemLegend(node) {
    const label = node.querySelector(':scope > .menu-fields [data-field="label"]')?.value || t('menu_item', 'Menu Item');
    const hidden = node.classList.contains('menu-item-is-hidden');
    const legend = node.querySelector('.menu-item-title');

    if (legend) {
        legend.textContent = hidden ? `${label} (${t('menu_hidden', 'Hidden')})` : label;
    }
}

// ----------------------------
// Hidden state, down the branch
// ----------------------------
// Hiding an item takes its whole branch off the site, so the rows underneath say
// so: they are dimmed like a hidden item, and each carries the reason. A child's
// own switch is left alone — it keeps meaning "this item", not "this branch".
function refreshHiddenState() {
    const walk = (list, inherited) => {
        [...list.children].forEach(item => {
            if (!item.classList.contains('menu-item')) return;

            const own = !!item.querySelector(':scope > .menu-actions [data-field="hidden"]')?.checked;
            const under = inherited && !own;

            item.classList.toggle('menu-item-is-hidden', own);
            item.classList.toggle('menu-item-under-hidden', under);
            updateMenuItemLegend(item);

            const note = item.querySelector(':scope > [data-item-note]');
            if (note) {
                note.hidden = !under;
                note.textContent = under ? t('menu_hidden_with_parent', 'Hidden with its parent') : '';
            }

            walk(item.querySelector(':scope > .children-container'), inherited || own);
        });
    };

    walk(container, false);
}

// ----------------------------
// Load initial menu
// ----------------------------
initialItems.forEach(item => container.appendChild(createMenuItem(item)));
renumberMenuItems();

// The root list is empty until the loop above fills it, so it is bound after.
bindSortableList(container);

// ----------------------------
// Add top-level page item
// ----------------------------
addLinkButton.addEventListener('click', async () => {
    const pageSelect = linkSelect;

    if (!pageSelect.value) {
        await window.confirmModal({
            title: t('menu_error_no_page_title', 'Missing selection'),
            message: t('menu_error_no_page', 'Choose something to link to first.'),
            simple: true,
        });
        return;
    }

    const selectedOption = pageSelect.selectedOptions[0];
    const itemData = {
        type: selectedOption.dataset.type || 'page',
        label: selectedOption.dataset.label || selectedOption.text,
        slug: selectedOption.dataset.slug || '',
        content_id: selectedOption.dataset.id || '',
        target: '_self',
        hidden: false,
        url: selectedOption.dataset.url || '',
        broken: false,
        children: []
    };

    container.appendChild(createMenuItem(itemData));
    pageSelect.value = '';
    addLinkButton.disabled = true;
    renumberMenuItems();
});

// ----------------------------
// Add top-level custom URL item
// ----------------------------
document.getElementById('add-url-item').addEventListener('click', async () => {
    const urlInput = document.getElementById('new-item-url');
    const labelInput = document.getElementById('new-item-label');
    const targetSelect = document.getElementById('new-item-target');

    if (!urlInput.value || !labelInput.value) {
        await confirmModal({
            title: t('menu_error_missing_title', 'Missing information'),
            message: t('menu_error_url_and_label', 'Enter both URL and label.'),
            simple: true,
        });
        return;
    }

    const itemData = {
        type: 'url',
        label: labelInput.value,
        slug: urlInput.value,
        target: targetSelect.value || '_self',
        children: []
    };

    container.appendChild(createMenuItem(itemData));

    // Reset inputs
    urlInput.value = '';
    labelInput.value = '';
    targetSelect.value = '_self';

    renumberMenuItems();
});

// ----------------------------
// Event delegation for menu item buttons
// ----------------------------
// The row buttons are icon-only, so a real click lands on the <path> inside the
// button rather than on the button itself. Match with closest() — which does
// traverse into inline SVG — and not with target.classList, which does not.
container.addEventListener('click', async e => {
    const item = e.target.closest('.menu-item');
    if (!item) return;

    const removeBtn = e.target.closest('.remove');
    const addChildBtn = e.target.closest('.add-child');
    const duplicateBtn = e.target.closest('.duplicate');

    // Remove
    if (removeBtn) {
        const hasChildren =
            item.querySelector('.children-container')?.children.length > 0;

        let message = t('menu_remove_confirm', 'Remove this menu item?');
        if (hasChildren) {
            message = t('menu_remove_confirm_children', 'Remove this menu item and child items?');
        }

        const ok = await confirmModal({
            title: t('menu_remove_title', 'Remove menu item'),
            message: message,
        });

        if (!ok) return;

        item.remove();
        renumberMenuItems();
        return;
    }

    // Add child
    if (addChildBtn) {
        const children = item.querySelector('.children-container');
        children.appendChild(createMenuItem());
        renumberMenuItems();
        return;
    }

    // Duplicate
    if (duplicateBtn) {
        const data = extractMenuItemData(item);
        item.after(createMenuItem(data));
        renumberMenuItems();
    }
});

// ----------------------------
// Extract menu item data recursively
// ----------------------------
function extractMenuItemData(el) {
    const data = {};
    const children = [];

    el.querySelectorAll(':scope > .menu-fields .field-input, :scope > .menu-actions .field-input').forEach(input => {
        const key = input.dataset.field;
        if (!key) return;

        if (input.type === 'checkbox') {
            data[key] = input.checked;
        } else {
            data[key] = input.value;
        }
    });

    el.querySelectorAll(':scope > .children-container > .menu-item')
        .forEach(child => children.push(extractMenuItemData(child)));

    data.url = el.dataset.url || '';
    data.broken = el.dataset.broken === '1';

    if (children.length) data.children = children;
    return data;
}



// ----------------------------
// Renumber menu item inputs for POST
// ----------------------------
function renumberMenuItems() {
    renumberContainer(container, '');
    refreshHiddenState();
}

function renumberContainer(parent, prefix) {
    const items = parent.querySelectorAll(':scope > .menu-item');

    items.forEach((item, i) => {
        const path = prefix === '' ? `items[${i}]` : `${prefix}[children][${i}]`;
        item.querySelectorAll(':scope > .menu-fields .field-input, :scope > .menu-actions .field-input').forEach(input => {
            const key = input.dataset.field;
            input.name = `${path}[${key}]`;
        });

        const children = item.querySelector('.children-container');
        if (children) renumberContainer(children, path);
    });
}

document.getElementById('menu-save').addEventListener('submit', () => {
    renumberMenuItems();
});
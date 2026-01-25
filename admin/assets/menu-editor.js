// ----------------------------
// Menu Editor JS
// ----------------------------

const container = document.getElementById('menu-items-container');
const template = document.getElementById('menu-item-template');
const initialItems = window.initialMenuItems || [];

// ----------------------------
// Create a menu item node
// ----------------------------
function createMenuItem(data = {}) {
    const node = template.content.firstElementChild.cloneNode(true);

    // Populate fields
    node.querySelectorAll('.field-input').forEach(input => {
        const key = input.dataset.field;
        if (!key) return;

        if (input.type === 'checkbox') {
            input.checked = !!data[key];
        } else {
            input.value = data[key] ?? '';
        }
    });

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
    node.querySelector('[data-field="label"]').addEventListener('input', () => {
        updateMenuItemLegend(node);
    });

    return node;
}

// ----------------------------
// Update <legend> label
// ----------------------------
function updateMenuItemLegend(node) {
    const label = node.querySelector('[data-field="label"]')?.value || 'Menu Item';
    const legend = node.querySelector('.menu-item-title');
    if (legend) legend.textContent = label;
}

// ----------------------------
// Load initial menu
// ----------------------------
initialItems.forEach(item => container.appendChild(createMenuItem(item)));
renumberMenuItems();

// ----------------------------
// Add top-level page item
// ----------------------------
document.getElementById('add-page-item').addEventListener('click', () => {
    const pageSelect = document.getElementById('new-item-page');
    if (!pageSelect.value) return alert('Select a page first.');

    const selectedOption = pageSelect.selectedOptions[0];
    const itemData = {
        type: 'page',
        label: selectedOption.text,
        slug: pageSelect.value,
        target: '_self',
        children: []
    };

    container.appendChild(createMenuItem(itemData));
    pageSelect.value = '';
    renumberMenuItems();
});

// ----------------------------
// Add top-level custom URL item
// ----------------------------
document.getElementById('add-url-item').addEventListener('click', () => {
    const urlInput = document.getElementById('new-item-url');
    const labelInput = document.getElementById('new-item-label');
    const targetSelect = document.getElementById('new-item-target');

    if (!urlInput.value || !labelInput.value) return alert('Enter both URL and label.');

    const itemData = {
        type: 'url',
        label: labelInput.value,
        url: urlInput.value,
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
container.addEventListener('click', async e => {
    const item = e.target.closest('.menu-item');
    if (!item) return;

    // Remove
    if (e.target.classList.contains('remove')) {
        const hasChildren =
            item.querySelector('.children-container')?.children.length > 0;

        let message = 'Remove this menu item?';
        if (hasChildren) {
            message = 'Remove this menu item and child items?';
        }

        const ok = await confirmModal({
            title: 'Remove menu item',
            message: message,
        });

        if (!ok) return;

        item.remove();
        renumberMenuItems();
    }

    // Add child
    if (e.target.classList.contains('add-child')) {
        const children = item.querySelector('.children-container');
        children.appendChild(createMenuItem());
        renumberMenuItems();
    }

    // Move up
    if (e.target.classList.contains('move-up')) {
        const prev = item.previousElementSibling;
        if (prev) prev.before(item);
        renumberMenuItems();
    }

    // Move down
    if (e.target.classList.contains('move-down')) {
        const next = item.nextElementSibling;
        if (next) next.after(item);
        renumberMenuItems();
    }

    // Duplicate
    if (e.target.classList.contains('duplicate')) {
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

    el.querySelectorAll('.field-input').forEach(input => {
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

    if (children.length) data.children = children;
    return data;
}



// ----------------------------
// Renumber menu item inputs for POST
// ----------------------------
function renumberMenuItems() {
    renumberContainer(container, '');
}

function renumberContainer(parent, prefix) {
    const items = parent.querySelectorAll(':scope > .menu-item');

    items.forEach((item, i) => {
        const path = prefix === '' ? String(i) : `${prefix}[children][${i}]`;
        item.querySelectorAll('.field-input').forEach(input => {
            const key = input.dataset.field;
            input.name = `items[${path}][${key}]`;
        });

        const children = item.querySelector('.children-container');
        if (children) renumberContainer(children, path);
    });
}
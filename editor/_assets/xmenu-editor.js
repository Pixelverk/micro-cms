// ----------------------------
// Menu Editor JS
// ----------------------------

const container = document.getElementById('menu-items-container');
const template = document.getElementById('menu-item-template');
const initialItems = window.initialMenuItems || [];

// ----------------------------
// Create a menu item node from template
// ----------------------------
function createMenuItem(data = {}) {
    const node = template.content.firstElementChild.cloneNode(true);

    // Populate fields from data
    node.querySelectorAll('.field-input').forEach(input => {
        const key = input.dataset.field;
        if (!key) return;

        if (input.type === 'checkbox') {
            input.checked = !!data[key];
        } else {
            input.value = data[key] ?? '';
        }
    });

    // Recursively create children
    const childrenContainer = node.querySelector('.children-container');
    if (Array.isArray(data.children)) {
        data.children.forEach(child => {
            childrenContainer.appendChild(createMenuItem(child));
        });
    }

    return node;
}

// ----------------------------
// Load initial menu items
// ----------------------------
initialItems.forEach(item => container.appendChild(createMenuItem(item)));
renumberMenuItems();

// ----------------------------
// Add top-level menu item
// ----------------------------
document.getElementById('add-menu-item').addEventListener('click', () => {
    container.appendChild(createMenuItem());
    renumberMenuItems();
});

// ----------------------------
// Event delegation for item actions
// ----------------------------
container.addEventListener('click', e => {
    const item = e.target.closest('.menu-item');
    if (!item) return;

    // Remove
    if (e.target.classList.contains('remove')) {
        const hasChildren = item.querySelector('.children-container')?.children.length > 0;
        let msg = 'Remove this menu item?';
        if (hasChildren) msg += '\n\nThis will also remove all child items.';
        if (!confirm(msg)) return;

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
// Extract menu item data recursively (for POST & duplicate)
// ----------------------------
function extractMenuItemData(el) {
    const data = {};
    const children = [];

    el.querySelectorAll('.field-input').forEach(input => {
        const key = input.dataset.field;
        if (!key) return;

        if (input.type === 'checkbox') data[key] = input.checked;
        else data[key] = input.value;
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
const container = document.getElementById('components-container');
const availableComponents = window.availableComponents || {};

let componentCount = Math.max(
    0,
    ...Array.from(container.querySelectorAll('.component'))
        .map(fs => fs.dataset.path.split('-').map(Number))
        .flat()
) + 1;

// Element builder helper bro
function el(tag, { className, text, attrs } = {}, children = []) {
    const node = document.createElement(tag);

    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    if (attrs) {
        for (const [k, v] of Object.entries(attrs)) {
            node.setAttribute(k, v);
        }
    }

    children.forEach(child => node.appendChild(child));
    return node;
}

// ----------------------------
// Add a component dynamically
// ----------------------------
function addComponent(type, parentContainer = container, parentPath = null) {
    if (!type || !availableComponents[type]) return;

    const schema = availableComponents[type];
    const path = parentPath !== null ? `${parentPath}-${componentCount}` : `${componentCount}`;

    const fieldset = el('fieldset', {
        className: 'component',
        attrs: { 'data-path': path }
    }, [
        el('legend', { text: type }),

        el('input', {
            attrs: {
                type: 'hidden',
                name: `components[${path}][type]`,
                value: type
            }
        })
    ]);

    // Props
    for (const [propName, field] of Object.entries(schema)) {
        const inputName = `components[${path}][props][${propName}]`;
        const value = field.default ?? '';
        const labelText = field.label || propName;

        const input = field.type === 'textarea'
            ? el('textarea', { attrs: { name: inputName } }, [])
            : el('input', { attrs: { type: 'text', name: inputName, value } });

        if (field.type === 'textarea') input.value = value;

        fieldset.appendChild(
            el('label', {}, [
                document.createTextNode(`${labelText}: `),
                input
            ])
        );
    }

    // Children container
    const childrenDiv = el('div', { className: 'children-container' });
    fieldset.appendChild(childrenDiv);

    // Actions
    fieldset.appendChild(
        el('div', { className: 'component-actions' }, [
            el('button', {
                className: 'add-child-btn',
                text: 'Add Child Component',
                attrs: { type: 'button' }
            }),
            el('div', { className: 'actions-right' }, [
                el('button', { className: 'duplicate-btn', text: '⧉', attrs: { type: 'button' } }),
                el('button', { className: 'move-up', text: '↑', attrs: { type: 'button' } }),
                el('button', { className: 'move-down', text: '↓', attrs: { type: 'button' } }),
                el('button', { className: 'remove-btn', text: '×', attrs: { type: 'button' } })
            ])
        ])
    );

    parentContainer.appendChild(fieldset);
    componentCount++;
}

// ----------------------------
// Top-level Add Component
// ----------------------------
document.getElementById('add-component').addEventListener('click', () => {
    const select = document.getElementById('new-component-select');
    const type = select.value;
    addComponent(type);
    select.value = '';
});

// ----------------------------
// Event delegation for remove/add-child
// ----------------------------
container.addEventListener('click', e => {
    // Remove component
    if (e.target.matches('.remove-btn')) {
        e.target.closest('.component').remove();
        return;
    }

    // Add child component
    if (e.target.matches('.add-child-btn')) {
        const fs = e.target.closest('.component');
        const childrenContainer = fs.querySelector('.children-container');
        const childType = prompt("Enter child component type:");
        if (!childType || !availableComponents[childType]) {
            return alert("Invalid component type");
        }
        addComponent(childType, childrenContainer, fs.dataset.path);
    }
});

// make a slug
function slugify(value) {
    return value
        .toLowerCase()
        .trim()
        .replace(/[\s_]+/g, '-')     // spaces & underscores → dash
        .replace(/[^a-z0-9-]/g, '')  // remove invalid chars
        .replace(/-+/g, '-')         // collapse dashes
        .replace(/^-+|-+$/g, '');    // trim dashes
}

// update hidden slug field when title changes
const titleInput = document.getElementById('title');
const slugInput = document.getElementById('slug');

let slugTouched = false;

// If slug ever changes manually, stop auto-sync
slugInput.addEventListener('change', () => {
    slugTouched = true;
    // slugify the value
    const slug = slugify(slugInput.value);
    slugInput.value = slug;
});

// If slug was already set on existing page
if (titleInput.value != slugInput.value && slugInput.value != '' ){
    slugTouched = true;
};

// Auto-generate slug from title
titleInput.addEventListener('input', () => {
    if (slugTouched) return;
    slugInput.value = slugify(titleInput.value);
});

//
// Listen for clicks on move up/down buttons
//
document.addEventListener('click', e => {
    if (e.target.classList.contains('move-up')) {
        const comp = e.target.closest('.component');
        const prev = comp.previousElementSibling;
        if (prev) prev.before(comp);
        renumberComponents();
    }

    if (e.target.classList.contains('move-down')) {
        const comp = e.target.closest('.component');
        const next = comp.nextElementSibling;
        if (next) next.after(comp);
        renumberComponents();
    }
});

// reorder components
function renumberComponents() {
    const root = document.getElementById('components-container');
    renumberContainer(root, '');
}

function renumberContainer(container, prefix) {
    const components = container.querySelectorAll(':scope > .component');

    components.forEach((comp, index) => {
        const path = prefix === '' ? String(index) : `${prefix}-${index}`;
        comp.dataset.path = path;

        // Update hidden type input
        const typeInput = comp.querySelector('input[name$="[type]"]');
        if (typeInput) {
            typeInput.name = `components[${path}][type]`;
        }

        // Update props
        comp.querySelectorAll('[name^="components["]').forEach(input => {
            input.name = input.name.replace(
                /components\[[^\]]+\]/,
                `components[${path}]`
            );
        });

        // Recurse children
        const children = comp.querySelector('.children-container');
        if (children) {
            renumberContainer(children, path);
        }
    });
}

// listen for duplicate click
document.addEventListener('click', e => {
    if (!e.target.classList.contains('duplicate-btn')) return;

    const original = e.target.closest('.component');
    if (!original) return;

    duplicateComponent(original);
});

function duplicateComponent(original) {
    const clone = original.cloneNode(true);

    // Remove path – will be reassigned
    delete clone.dataset.path;

    // Remove any IDs inside the clone
    clone.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));

    // Insert after original
    original.after(clone);

    // Renumber everything
    renumberComponents();
}
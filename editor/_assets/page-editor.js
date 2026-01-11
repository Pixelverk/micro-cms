const container = document.getElementById('components-container');
const availableComponents = window.availableComponents || {};
const initialComponents = window.initialComponents || [];

// component templates
const componentTemplate = document.getElementById('component-template');
const fieldTemplate = document.getElementById('field-template');       // default text input
const textareaTemplate = document.getElementById('textarea-template'); // multi-line text
const numberTemplate = document.getElementById('number-template');     // number input
const colorTemplate = document.getElementById('color-template');       // color picker
const checkboxTemplate = document.getElementById('checkbox-template'); // checkbox
const urlTemplate = document.getElementById('url-template');           // URL input
const emailTemplate = document.getElementById('email-template');       // email input

// ----------------------------
// Create a component from schema and optional data
// ----------------------------
function createComponent(type, data = {}) {
    const node = componentTemplate.content.firstElementChild.cloneNode(true);
    node.dataset.type = type;

    // Set title and hidden type input
    node.querySelector('.component-title').textContent = type;
    const typeInput = node.querySelector('.component-type');
    typeInput.value = type;
    typeInput.name = `components[][type]`; // will be renumbered later

    const fieldsContainer = node.querySelector('.component-fields');
    const schema = availableComponents[type] || {};
    const props = data.props || {};

    // Create fields
    for (const [name, field] of Object.entries(schema)) {
        const value = props[name] ?? field.default ?? '';

        // Pick template based on field type
        let tpl;
        switch (field.type) {
            case 'textarea':
                tpl = textareaTemplate;
                break;
            case 'number':
                tpl = numberTemplate;
                break;
            case 'color':
                tpl = colorTemplate;
                break;
            case 'checkbox':
                tpl = checkboxTemplate;
                break;
            case 'url':
                tpl = urlTemplate;
                break;
            case 'email':
                tpl = emailTemplate;
                break;
            default:
                tpl = fieldTemplate;
        }

        const fieldNode = tpl.content.firstElementChild.cloneNode(true);
        fieldNode.querySelector('.field-label').textContent = field.label || name;
        const input = fieldNode.querySelector('.field-input');

        // Set value appropriately
        if (field.type === 'checkbox') {
            input.checked = !!value;
        } else {
            input.value = value;
        }

        input.name = `components[][props][${name}]`; // will be renumbered later

        fieldsContainer.appendChild(fieldNode);
    }

    // Recursively create children
    const childrenContainer = node.querySelector('.children-container');
    if (Array.isArray(data.children)) {
        data.children.forEach(childData => {
            const childNode = createComponent(childData.type, childData);
            childrenContainer.appendChild(childNode);
        });
    }

    return node;
}

// ----------------------------
// Load initial components
// ----------------------------
initialComponents.forEach(data => {
    const compNode = createComponent(data.type, data);
    container.appendChild(compNode);
});
renumberComponents();

// ----------------------------
// Top-level Add Component
// ----------------------------
document.getElementById('add-component').addEventListener('click', () => {
    const type = document.getElementById('new-component-select').value;
    if (!type || !availableComponents[type]) return;

    const comp = createComponent(type);
    container.appendChild(comp);
    renumberComponents();
});

// ----------------------------
// Event delegation: remove, add-child, move, duplicate
// ----------------------------
container.addEventListener('click', e => {
    const comp = e.target.closest('.component');
    if (!comp) return;

    // Remove
    if (e.target.classList.contains('remove-btn')) {
        comp.remove();
        renumberComponents();
        return;
    }

    // Add child
    if (e.target.classList.contains('add-child-btn')) {
        const childrenContainer = comp.querySelector('.children-container');

        const type = prompt(
            'Enter component type:\n\n' +
            Object.keys(availableComponents).join(', ')
        );
        if (!type || !availableComponents[type]) {
            alert('Invalid component type');
            return;
        }

        const child = createComponent(type);
        childrenContainer.appendChild(child);
        renumberComponents();
        return;
    }

    // Move up
    if (e.target.classList.contains('move-up')) {
        const prev = comp.previousElementSibling;
        if (prev) prev.before(comp);
        renumberComponents();
        return;
    }

    // Move down
    if (e.target.classList.contains('move-down')) {
        const next = comp.nextElementSibling;
        if (next) next.after(comp);
        renumberComponents();
        return;
    }

    // Duplicate
    if (e.target.classList.contains('duplicate-btn')) {
        duplicateComponent(comp);
        return;
    }
});

// ----------------------------
// Duplicate Component
// ----------------------------
function extractComponentData(componentEl) {
    const type = componentEl.dataset.type;
    const props = {};
    const children = [];

    // Extract props from inputs & textareas
    componentEl.querySelectorAll('input[name], textarea[name]').forEach(input => {
        const nameMatch = input.name.match(/\[([^\]]+)\]$/);
        if (!nameMatch) return;
        const propName = nameMatch[1];

        if (input.type === 'checkbox') {
            props[propName] = input.checked;
        } else if (input.type === 'number') {
            props[propName] = input.value !== '' ? parseFloat(input.value) : '';
        } else {
            props[propName] = input.value;
        }
    });

    // Recursively extract children
    const childrenContainer = componentEl.querySelector('.children-container');
    if (childrenContainer) {
        childrenContainer.querySelectorAll(':scope > .component').forEach(child => {
            children.push(extractComponentData(child));
        });
    }

    return { type, props, children };
}

function duplicateComponent(componentEl) {
    const data = extractComponentData(componentEl);
    const clone = createComponent(data.type, data);
    componentEl.after(clone);
    renumberComponents();
}

// ----------------------------
// Renumber components for form POST
// ----------------------------
function renumberComponents() {
    renumberContainer(container, '');
}

function renumberContainer(parentContainer, prefix) {
    const components = parentContainer.querySelectorAll(':scope > .component');
    components.forEach((comp, i) => {
        const path = prefix === '' ? String(i) : `${prefix}-${i}`;
        comp.dataset.path = path;

        // Hidden type input
        const typeInput = comp.querySelector('.component-type');
        if (typeInput) typeInput.name = `components[${path}][type]`;

        // Fields
        comp.querySelectorAll('.field-input').forEach(input => {
            const nameMatch = input.name.match(/\[([^\]]+)\]$/);
            if (!nameMatch) return;
            const propName = nameMatch[1];
            input.name = `components[${path}][props][${propName}]`;
        });

        // Recurse children
        const childrenContainer = comp.querySelector('.children-container');
        if (childrenContainer) {
            renumberContainer(childrenContainer, path);
        }
    });
}

// ----------------------------
// Slug auto-generation
// ----------------------------
function slugify(value) {
    return value.toLowerCase().trim()
        .replace(/[\s_]+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '');
}

const titleInput = document.getElementById('title');
const slugInput = document.getElementById('slug');
let slugTouched = false;

slugInput.addEventListener('change', () => { slugTouched = true; slugInput.value = slugify(slugInput.value); });
if (titleInput.value != slugInput.value && slugInput.value != '') slugTouched = true;
titleInput.addEventListener('input', () => { if (!slugTouched) slugInput.value = slugify(titleInput.value); });
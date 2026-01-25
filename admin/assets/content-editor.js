// ----------------------------
// Content Editor JS
// ----------------------------

const container = document.getElementById('components-container');
const availableComponents = window.availableComponents || {};
const initialComponents = window.initialComponents || [];
const contentType = window.contentType || 'page'; // NEW: generic content type

// Templates
const componentTemplate = document.getElementById('component-template');
const fieldTemplate = document.getElementById('field-template');
const textareaTemplate = document.getElementById('textarea-template');
const numberTemplate = document.getElementById('number-template');
const colorTemplate = document.getElementById('color-template');
const checkboxTemplate = document.getElementById('checkbox-template');
const urlTemplate = document.getElementById('url-template');
const emailTemplate = document.getElementById('email-template');
const quillTemplate = document.getElementById('quill-editor-template');

// ----------------------------
// Create a component from schema + data
// ----------------------------
function createComponent(type, data = {}) {
    if (!availableComponents[type]) {
        console.warn(`Unknown component type "${type}"`);
        return document.createComment(`Unknown component: ${type}`);
    }

    const node = componentTemplate.content.firstElementChild.cloneNode(true);
    node.dataset.type = type;

    node.querySelector('.component-title').textContent = type;

    const typeInput = node.querySelector('.component-type');
    typeInput.value = type;
    typeInput.name = `components[][type]`;

    // Fields
    const fieldsContainer = node.querySelector('.component-fields');
    const schema = availableComponents[type] || {};
    const fieldSchema = schema.schema || {};
    const props = data.props || {};

    // 
    for (const [name, field] of Object.entries(fieldSchema)) {
        const value = props[name] ?? field.default ?? '';
        const fieldType = field.type || 'string';
        let tpl;

        // check field type
        switch (fieldType) {
            case 'textarea': tpl = textareaTemplate; break;
            case 'number': tpl = numberTemplate; break;
            case 'color': tpl = colorTemplate; break;
            case 'checkbox': tpl = checkboxTemplate; break;
            case 'url': tpl = urlTemplate; break;
            case 'email': tpl = emailTemplate; break;
            case 'quill': tpl = quillTemplate; break;
            default: tpl = fieldTemplate;
        }

        const fieldNode = tpl.content.firstElementChild.cloneNode(true);
        fieldNode.querySelector('.field-label').textContent = field.label || name;
        const input = fieldNode.querySelector('.field-input');

        // quill special
        if (fieldType === 'quill') {
            const editorEl = fieldNode.querySelector('.quill-editor');
            const hiddenInput = fieldNode.querySelector('.quill-hidden');

            hiddenInput.name = `components[][props][${name}]`;
            hiddenInput.value = value || '';

            const quill = new Quill(editorEl, {
                theme: 'snow'
            });

            quill.root.innerHTML = hiddenInput.value;

            quill.on('text-change', () => {
                hiddenInput.value = quill.root.innerHTML;
            });

            fieldsContainer.appendChild(fieldNode);
            continue;
        }

        // regular fields
        if (fieldType === 'checkbox') input.checked = !!value;
        else input.value = value;

        input.name = `components[][props][${name}]`; // renumber later
        fieldsContainer.appendChild(fieldNode);
    }

    // Children
    const noChildren = node.querySelector('#no-children');
    const addBtn = node.querySelector('.add-child-btn');
    const select = node.querySelector('.allowed-children-select');

    const childrenSetting = schema.children || 'any';
    const allowedChildren = schema.allowed_children || [];

    if (childrenSetting !== 'none') {
        noChildren.style.display = 'none';
        addBtn.disabled = true;

        if (select) {
            select.addEventListener('change', () => { addBtn.disabled = !select.value; });
            select.innerHTML = '<option value="">-- Child Component --</option>';

            let childOptions = [];
            if (childrenSetting === 'any') childOptions = Object.keys(availableComponents);
            else if (childrenSetting === 'some') childOptions = allowedChildren.filter(c => availableComponents[c]);

            childOptions.forEach(childType => {
                /* console.log(childOptions); */
                const option = document.createElement('option');
                option.value = childType;
                option.textContent = availableComponents[childType]['label'];
                select.appendChild(option);
            });
        }
    } else {
        addBtn.style.display = 'none';
        select.style.display = 'none';
    }

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
if (Array.isArray(initialComponents)) {
    initialComponents.forEach(data => {
        container.appendChild(createComponent(data.type, data));
    });
} else if (initialComponents && typeof initialComponents === 'object') {
    Object.values(initialComponents).forEach(data => {
        container.appendChild(createComponent(data.type, data));
    });
}

renumberComponents();

// ----------------------------
// Add top-level component
// ----------------------------
const addBtn = document.getElementById('add-component');
const select = document.getElementById('new-component-select');

if (addBtn && select) {
    addBtn.addEventListener('click', async () => {
        const type = select.value;

        if (!type || !availableComponents[type]) {
            await window.confirmModal({
                title: 'Invalid component',
                message: 'Please select a valid component.',
                simple: true,
            });
            return;
        }

        container.appendChild(createComponent(type));
        renumberComponents();
    });
}

// ----------------------------
// Event delegation (remove, add-child, move, duplicate)
// ----------------------------
container.addEventListener('click', async e => {
    const comp = e.target.closest('.component');
    if (!comp) return;

    // Remove
    if (e.target.classList.contains('remove-btn')) {
        const type = comp.dataset.type;
        const hasChildren = comp.querySelector('.children-container')?.children.length > 0;

        let message = `Remove "${type}" component?`;
        if (hasChildren) message = `Remove "${type}" and child components?`;

        const ok = await confirmModal({
            title: 'Remove component',
            message: message,
        });

        if (!ok) return;

        comp.remove();
        renumberComponents();
        return;
    }

    // Add child
    if (e.target.classList.contains('add-child-btn')) {
        const controls = e.target.closest('.actions-left');
        const parent = e.target.closest('.component');
        const childrenContainer = parent.querySelector('.children-container');
        const select = controls.querySelector('.allowed-children-select');

        const type = select.value;

        if (!type || !availableComponents[type]) {
            await window.confirmModal({
                title: 'Invalid component',
                message: 'Please select a valid child component.',
                simple: true,
            });
            return;
        }

        childrenContainer.appendChild(createComponent(type));
        renumberComponents();
        select.value = '';
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
// Duplicate
// ----------------------------
function extractComponentData(compEl) {
    const type = compEl.dataset.type;
    const props = {};
    const children = [];

    compEl.querySelectorAll('input[name], textarea[name]').forEach(input => {
        const nameMatch = input.name.match(/\[([^\]]+)\]$/);
        if (!nameMatch) return;
        const propName = nameMatch[1];
        if (input.type === 'checkbox') props[propName] = input.checked;
        else if (input.type === 'number') props[propName] = input.value !== '' ? parseFloat(input.value) : '';
        else props[propName] = input.value;
    });

    const childrenContainer = compEl.querySelector('.children-container');
    if (childrenContainer) {
        childrenContainer.querySelectorAll(':scope > .component').forEach(child => {
            children.push(extractComponentData(child));
        });
    }

    return { type, props, children };
}

function duplicateComponent(compEl) {
    const data = extractComponentData(compEl);
    const clone = createComponent(data.type, data);
    compEl.after(clone);
    renumberComponents();
}

// ----------------------------
// Renumber
// ----------------------------
function renumberComponents() { renumberContainer(container, ''); }

function renumberContainer(parent, prefix) {
    parent.querySelectorAll(':scope > .component').forEach((comp, i) => {
        const path = prefix === '' ? String(i) : `${prefix}-${i}`;
        comp.dataset.path = path;

        const typeInput = comp.querySelector('.component-type');
        if (typeInput) typeInput.name = `components[${path}][type]`;

        comp.querySelectorAll('.field-input').forEach(input => {
            const nameMatch = input.name.match(/\[([^\]]+)\]$/);
            if (!nameMatch) return;
            const propName = nameMatch[1];
            input.name = `components[${path}][props][${propName}]`;
        });

        const childrenContainer = comp.querySelector('.children-container');
        if (childrenContainer) renumberContainer(childrenContainer, path);
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
const slugInput  = document.getElementById('slug');

if (titleInput && slugInput) {
    let slugTouched = false;

    slugInput.addEventListener('change', () => {
        slugTouched = true;
        slugInput.value = slugify(slugInput.value);
    });

    if (titleInput.value !== slugInput.value && slugInput.value !== '') {
        slugTouched = true;
    }

    titleInput.addEventListener('input', () => {
        if (!slugTouched) {
            slugInput.value = slugify(titleInput.value);
        }
    });
}
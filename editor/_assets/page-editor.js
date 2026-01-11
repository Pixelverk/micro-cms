const container = document.getElementById('components-container');
const availableComponents = window.availableComponents || {};

let componentCount = Math.max(
    0,
    ...Array.from(container.querySelectorAll('.component'))
        .map(fs => fs.dataset.path.split('-').map(Number))
        .flat()
) + 1;

// ----------------------------
// Add a component dynamically
// ----------------------------
function addComponent(type, parentContainer = container, parentPath = null) {
    if (!type || !availableComponents[type]) return;

    const schema = availableComponents[type];
    const path = parentPath !== null ? `${parentPath}-${componentCount}` : `${componentCount}`;

    const fs = document.createElement('fieldset');
    fs.classList.add('component');
    fs.dataset.path = path;

    // Legend
    const legend = document.createElement('legend');
    legend.textContent = type;
    fs.appendChild(legend);

    // Hidden type input
    const hiddenType = document.createElement('input');
    hiddenType.type = 'hidden';
    hiddenType.name = `components[${path}][type]`;
    hiddenType.value = type;
    fs.appendChild(hiddenType);

    // Props inputs
    for (const [propName, field] of Object.entries(schema)) {
        const label = document.createElement('label');
        const value = field.default || '';
        if ((field.type || 'string') === 'textarea') {
            label.innerHTML = `${field.label || propName}: <textarea name="components[${path}][props][${propName}]">${value}</textarea>`;
        } else {
            label.innerHTML = `${field.label || propName}: <input type="text" name="components[${path}][props][${propName}]" value="${value}">`;
        }
        fs.appendChild(label);
    }

    // Children container
    const childrenDiv = document.createElement('div');
    childrenDiv.className = 'children-container';
    fs.appendChild(childrenDiv);

    // Actions
    const actionsDiv = document.createElement('div');
    actionsDiv.className = 'component-actions';

    const addChildBtn = document.createElement('button');
    addChildBtn.type = 'button';
    addChildBtn.textContent = 'Add Child Component';
    addChildBtn.className = 'add-child-btn';
    actionsDiv.appendChild(addChildBtn);

    const actionsRight = document.createElement('div');
    actionsRight.className = 'actions-right';
    actionsDiv.appendChild(actionsRight);

    const upBtn = document.createElement('button');
    upBtn.type = 'button';
    upBtn.textContent = '↑';
    upBtn.className = 'move-up';
    actionsRight.appendChild(upBtn);

    const downBtn = document.createElement('button');
    downBtn.type = 'button';
    downBtn.textContent = '↓';
    downBtn.className = 'move-down';
    actionsRight.appendChild(downBtn);

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.textContent = '×';
    removeBtn.className = 'remove-btn';
    actionsRight.appendChild(removeBtn);

    fs.appendChild(actionsDiv);
    parentContainer.appendChild(fs);
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
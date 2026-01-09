function getMaxPathNumber() {
    const allPaths = Array.from(document.querySelectorAll('.component'))
        .map(fs => fs.dataset.path)
        .flatMap(p => p.split('-').map(Number));
    return allPaths.length ? Math.max(...allPaths) + 1 : 0;
}

let componentCount = getMaxPathNumber();

const container = document.getElementById('components-container');
const availableComponents = window.availableComponents;

// Dynamically add a new component
async function addComponent(type, parentContainer = container, parentPath = null) {
    if(!type) return;

    try {
        await import(`/_components/${type}.js`);
    } catch(err) {
        console.error("Failed to load component:", type, err);
        return;
    }

    const elClass = customElements.get(type);
    const attrs = elClass?.observedAttributes ?? [];

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
    attrs.forEach(attr => {
        const label = document.createElement('label');
        label.innerHTML = `${attr}: <input type="text" name="components[${path}][props][${attr}]" value="">`;
        fs.appendChild(label);
    });

    // Children container
    const childrenDiv = document.createElement('div');
    childrenDiv.className = 'children-container';
    fs.appendChild(childrenDiv);

    // Component actions container
    const actionsDiv = document.createElement('div');
    actionsDiv.className = 'component-actions';
    fs.appendChild(actionsDiv);

    // Add Child button
    const addChildBtn = document.createElement('button');
    addChildBtn.type = 'button';
    addChildBtn.textContent = 'Add Child Component';
    addChildBtn.className = 'add-child-btn';
    actionsDiv.appendChild(addChildBtn);

    // Remove button
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.textContent = '×';
    removeBtn.className = 'remove-btn';
    actionsDiv.appendChild(removeBtn);

    parentContainer.appendChild(fs);
    componentCount++;
}

// Add top-level component button
document.getElementById('add-component').addEventListener('click', () => {
    const select = document.getElementById('new-component-select');
    const type = select.value;
    addComponent(type);
    select.value = "";
});

// Event delegation for remove buttons and add-child buttons
container.addEventListener('click', e => {
    // Remove component
    if (e.target.matches('.remove-btn')) {
        const fs = e.target.closest('.component');
        fs.remove();
        return;
    }

    // Add child component
    if (e.target.matches('.add-child-btn')) {
        const fs = e.target.closest('.component');
        const childrenContainer = fs.querySelector('.children-container');
        const childType = prompt("Enter child component type:");
        if (!childType || !availableComponents.includes(childType)) return alert("Invalid component type");

        addComponent(childType, childrenContainer, fs.dataset.path);
        return;
    }
});
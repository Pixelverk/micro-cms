// ----------------------------
// Content Editor JS
// ----------------------------

const container = document.getElementById('components-container');
const availableComponents = window.availableComponents || {};
const initialComponents = window.initialComponents || [];
const contentType = window.contentType || 'page';

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
const selectTemplate = document.getElementById('select-template');
const imageTemplate = document.getElementById('image-template');

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

    const titleEl = node.querySelector('.component-title');
    const label = availableComponents[type]?.label || type.replace(/-/g, ' ');
    titleEl.dataset.baseLabel = label;

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
            case 'select': tpl = selectTemplate; break;
            case 'image': tpl = imageTemplate; break;
            default: tpl = fieldTemplate;
        }

        const fieldNode = tpl.content.firstElementChild.cloneNode(true);
        fieldNode.querySelector('.field-label').textContent = field.label || name;
        const input = fieldNode.querySelector('.field-input');

        // quill logic 
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

        // select logic
        if (fieldType === 'select') {
            input.innerHTML = '';

            if (field.required) {
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = '-- Select --';
                input.appendChild(placeholder);
            }

            const options = field.options || {};

            for (const [optValue, label] of Object.entries(options)) {
                const opt = document.createElement('option');
                opt.value = optValue;
                opt.textContent = label;
                input.appendChild(opt);
            }

            if (value !== undefined && value !== '') {
                input.value = value;
            }
        }

        // image picker
        if (fieldType === 'image') {
            const preview = fieldNode.querySelector('.image-preview');
            const btn = fieldNode.querySelector('.select-image-btn');

            input.name = `components[][props][${name}]`;
            input.value = value || '';

            // find preview url from id
            if (value) {
                const media = (window.mediaImages || []).find(m => String(m.id) === String(value));

                if (media) {
                    let url = '';

                    if (media.formats?.webp?.length) url = '/media/' + media.formats.webp[0];
                    else {
                        const first = Object.values(media.formats || {})[0];
                        if (first?.length) url = '/media/' + first[0];
                    }

                    preview.src = url;
                    preview.alt = media.original_name;
                }
            }

            btn.addEventListener('click', () => {
                openImagePicker(input);
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
    const noChildren = node.querySelector('.no-children');
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
    
    // if child components are allowed, they should be sortable
    if (allowedChildren.length > 0 || childrenSetting == 'any') {       
        // Bind Sortable for this component's children container
        if (childrenContainer) {
            bindSortable(childrenContainer);
        }
    }

    // recurse to create existing child components
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

        // update title
        const titleEl = comp.querySelector('.component-title');
        if (titleEl) {
            const label = titleEl.dataset.baseLabel || comp.dataset.type;
            titleEl.textContent = `${formatPath(path)} – ${label}`;
        }

        // type input
        const typeInput = comp.querySelector('.component-type');
        if (typeInput) typeInput.name = `components[${path}][type]`;

        // fields
        comp.querySelectorAll('.field-input').forEach(input => {
            const nameMatch = input.name.match(/\[([^\]]+)\]$/);
            if (!nameMatch) return;
            const propName = nameMatch[1];
            input.name = `components[${path}][props][${propName}]`;
        });

        // recursive children
        const childrenContainer = comp.querySelector('.children-container');
        if (childrenContainer && childrenContainer.children.length > 0) renumberContainer(childrenContainer, path);
    });

}

function formatPath(path) {
    // "0-1-2" → "1.2.3"
    return path
        .split('-')
        .map(n => Number(n) + 1)
        .join('.');
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
    // Flag to prevent overwriting manually entered slug
    let slugTouched = slugInput.value.trim() !== '';

    // User manually edits slug
    slugInput.addEventListener('input', () => {
        slugTouched = true;
        slugInput.value = slugify(slugInput.value);
    });

    // Auto-update slug from title if not manually touched
    titleInput.addEventListener('input', () => {
        if (!slugTouched) {
            slugInput.value = slugify(titleInput.value);
        }
    });

    // Optional: if slug is empty on page load, populate from title
    if (!slugInput.value && titleInput.value) {
        slugInput.value = slugify(titleInput.value);
    }
}

// clear timedate input if draft or publish status selected
document.addEventListener('DOMContentLoaded', () => {
    const statusSelect = document.querySelector('select[name="status"]');
    const scheduledContainer = document.getElementById('scheduled-container');
    const scheduledInput = scheduledContainer.querySelector('input[name="scheduled_at"]');

    function updateScheduledVisibility() {
        if (statusSelect.value === 'scheduled') {
            scheduledContainer.style.display = '';
        } else {
            scheduledContainer.style.display = 'none';
            scheduledInput.value = ''; // clear input if hidden
        }
    }

    // Initial check on page load
    updateScheduledVisibility();

    // Update whenever the status changes
    statusSelect.addEventListener('change', updateScheduledVisibility);
});

// sorting of components 
function bindSortable(el) {
    new Sortable(el, {
        handle: '.component-title',
        animation: 150,
        ghostClass: 'sortable-ghost',
        onEnd: () => {
            renumberComponents();
        },
    });
}

// bind sortable on initial page load
bindSortable(container);

// drag and drop adding of components
const paletteItems = document.querySelectorAll('.draggable-component');

paletteItems.forEach(item => {
    item.addEventListener('dragstart', e => {
        e.dataTransfer.setData('component-type', item.dataset.type);
        e.dataTransfer.effectAllowed = 'copy';
    });
});

container.addEventListener('dragover', e => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
});

container.addEventListener('drop', e => {
    e.preventDefault();
    const type = e.dataTransfer.getData('component-type');
    if (!type) return;
    container.appendChild(createComponent(type));
    renumberComponents();
});

// ----------------------------
// Image Picker Modal Logic
// ----------------------------
const imagePickerModal = document.getElementById('image-picker-modal');
const imageGrid = document.getElementById('image-grid');
const imageSearch = document.getElementById('image-search');
let currentImageField = null;

// Open modal for a given input
function openImagePicker(fieldInput) {
    currentImageField = {
        hiddenInput: fieldInput,
        preview: fieldInput.closest('.image-picker-wrapper')?.querySelector('.image-preview')
    };
    renderImageGrid(window.mediaImages || []);
    imagePickerModal.classList.remove('hidden');
    imageSearch.value = '';
}

// Close modal
function closeImagePicker() {
    imagePickerModal.classList.add('hidden');
    currentImageField = null;
}

// Render images in modal
function renderImageGrid(images) {
    imageGrid.innerHTML = '';

    images.forEach(img => {
        // choose preview url: webp first, else any format
        let url = '';
        if (img.formats?.webp?.length) url = '/media/' + img.formats.webp[0];
        else if (img.formats) {
            const firstFmt = Object.values(img.formats)[0];
            if (firstFmt?.length) url = '/media/' + firstFmt[0];
        }

        const el = document.createElement('img');
        el.src = url;
        el.alt = img.original_name;
        el.title = img.original_name;
        el.dataset.id = img.id;

        el.addEventListener('click', () => {
            if (!currentImageField) return;

            currentImageField.hiddenInput.value = img.id; // save DB id
            currentImageField.preview.src = url;         // show preview
            currentImageField.preview.alt = img.original_name;

            closeImagePicker();
        });       

        imageGrid.appendChild(el);
    });
}

// Search filter
imageSearch.addEventListener('input', () => {
    const query = imageSearch.value.toLowerCase();
    const filtered = Object.values(window.mediaImages || {}).filter(img =>
        img.original_name.toLowerCase().includes(query)
    );
    renderImageGrid(filtered);
});

// Close modal
imagePickerModal.querySelector('.close-modal').addEventListener('click', closeImagePicker);

// ----------------------------
// Attach picker to 'image' fields
// ----------------------------
function attachImagePicker() {
    document.querySelectorAll('.field-input[type="text"][data-image-picker]').forEach(input => {
        // add a button next to input
        if (input.nextSibling?.classList?.contains('image-picker-btn')) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'Pick Image';
        btn.className = 'image-picker-btn';
        btn.style.marginLeft = '0.5rem';
        input.insertAdjacentElement('afterend', btn);

        btn.addEventListener('click', () => openImagePicker(input));

        // initialize preview if value exists
        const mediaId = input.value;
        if (mediaId && window.mediaImages[mediaId]) {
            const preview = input.closest('.image-picker-wrapper')?.querySelector('.image-preview');
            if (preview) preview.src = getPreviewUrl(window.mediaImages[mediaId]);
        }
    });
}

// helper to get preview URL
function getPreviewUrl(img) {
    if (!img) return '';
    if (img.formats?.webp?.length) return '/media/' + img.formats.webp[0];
    if (img.formats) {
        const firstFmt = Object.values(img.formats)[0];
        if (firstFmt?.length) return '/media/' + firstFmt[0];
    }
    return '';
}

// initial attach
attachImagePicker();
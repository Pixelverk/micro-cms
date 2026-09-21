// ----------------------------
// Content Editor JS
// ----------------------------

const t = (key, fallback) => window.adminTranslations?.[key] || fallback;

const container = document.getElementById('components-container');
const availableComponents = window.availableComponents || {};
const initialComponents = window.initialComponents || [];

// The Add component area is the container's last child, so a new component goes
// in above it: the area always sits under the last component. insertBefore()
// with no reference appends, which is what happens if the area is not there.
function appendComponent(node) {
    if (!container) return;

    container.insertBefore(node, container.querySelector('.component-add-zone'));
}

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
const iconTemplate = document.getElementById('icon-template');

// The icon browser's tiles are the inline SVGs themselves. Collect them up
// front, because the components on the page are built before the dialog is
// wired and each icon field paints its current glyph immediately.
const iconGlyphs = {};

document.querySelectorAll('#icon-picker [data-icon]').forEach(tile => {
    iconGlyphs[tile.dataset.icon] = tile.querySelector('svg')?.outerHTML || '';
});

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
            case 'icon': tpl = iconTemplate; break;
            default: tpl = fieldTemplate;
        }

        const fieldNode = tpl.content.firstElementChild.cloneNode(true);
        fieldNode.querySelector('.field-label').textContent = field.label || name;
        const input = fieldNode.querySelector('.field-input');

        // How much of the row the field takes. The schema value is normalised
        // server-side, so it is one of the classes the stylesheet knows; a field
        // that declares nothing is left to the grid.
        if (field.span) fieldNode.classList.add(`field-span-${field.span}`);

        // A rich-text field's hidden input carries the submitted HTML; Quill is
        // built over it by bootstrapQuillEditors() once the component is added.
        if (fieldType === 'quill') {
            const hiddenInput = fieldNode.querySelector('.quill-hidden');

            hiddenInput.name = `components[][props][${name}]`;
            hiddenInput.value = value || '';

            fieldsContainer.appendChild(fieldNode);
            continue;
        }

        // select logic
        if (fieldType === 'select') {
            input.innerHTML = '';

            if (field.required) {
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = t('editor_select_placeholder', '-- Select --');
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
            input.name = `components[][props][${name}]`;
            input.value = value || '';

            showImagePickerPreview(input);

            fieldNode.querySelector('.select-image-btn').addEventListener('click', (event) => openImagePicker(input, event.currentTarget));
            fieldNode.querySelector('.clear-image-btn').addEventListener('click', () => clearImagePicker(input));

            fieldsContainer.appendChild(fieldNode);
            continue;
        }

        // icon picker
        if (fieldType === 'icon') {
            input.name = `components[][props][${name}]`;
            input.value = value || '';

            showIconPreview(input);

            fieldNode.querySelector('.select-icon-btn').addEventListener('click', (event) => openIconPicker(input, event.currentTarget));
            fieldNode.querySelector('.clear-icon-btn').addEventListener('click', () => clearIconPicker(input));

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
// Rich text fields
// ----------------------------
// Every rich-text field, whether it belongs to a component or is the only field
// on the page, is built from #quill-editor-template and initialised by
// bootstrapQuillEditors() below. The content type's field carries its saved
// HTML in window.richTextField; a component's is already on its hidden input.
function initRichTextField() {
    const field = window.richTextField;
    const host = document.getElementById('rich-text-container');

    if (!field || !host) return;

    const node = quillTemplate.content.firstElementChild.cloneNode(true);
    const hiddenInput = node.querySelector('.quill-hidden');

    // The component's type travels with the field, like any other component's.
    node.dataset.type = field.component;
    node.insertBefore(Object.assign(document.createElement('input'), {
        type: 'hidden',
        className: 'component-type',
        name: 'components[0][type]',
        value: field.component,
    }), node.firstChild);

    hiddenInput.name = field.name;
    hiddenInput.value = field.value || '';

    host.appendChild(node);
}

// Every rich-text field, whether it belongs to a component or is the only field
// on the page, is initialised once: the hidden input it submits through is a
// sibling, and the marker keeps a re-run from building a second toolbar.
function bootstrapQuillEditors(root = document) {
    if (typeof Quill === 'undefined') return;

    root.querySelectorAll('.quill-editor').forEach(editorEl => {
        if (editorEl.dataset.quillBound) return;

        editorEl.dataset.quillBound = '1';

        const hiddenInput = editorEl.parentElement?.querySelector('.quill-hidden');
        const quill = new Quill(editorEl, { theme: 'snow' });

        if (!hiddenInput) return;

        quill.root.innerHTML = hiddenInput.value;

        quill.on('text-change', () => {
            hiddenInput.value = quill.root.innerHTML;
        });
    });
}

// Component mode builds the list from what the body holds; a rich-text-only
// content type has no list, so it only builds its one field.
if (container) {
    // ----------------------------
    // Load initial components
    // ----------------------------
    if (Array.isArray(initialComponents)) {
        initialComponents.forEach(data => {
            appendComponent(createComponent(data.type, data));
        });
    } else if (initialComponents && typeof initialComponents === 'object') {
        Object.values(initialComponents).forEach(data => {
            appendComponent(createComponent(data.type, data));
        });
    }

    renumberComponents();
} else {
    initRichTextField();
}

bootstrapQuillEditors();

// ----------------------------
// Event delegation (remove, add-child, move, duplicate)
// ----------------------------
if (container) container.addEventListener('click', async e => {
    const comp = e.target.closest('.component');
    if (!comp) return;

    // Remove
    if (e.target.classList.contains('remove-btn')) {
        const type = comp.dataset.type;
        const hasChildren = comp.querySelector('.children-container')?.children.length > 0;

        let message = t('editor_remove_confirm', 'Remove ":type" component?').replace(':type', type);
        if (hasChildren) message = t('editor_remove_confirm_children', 'Remove ":type" and child components?').replace(':type', type);

        const ok = await confirmModal({
            title: t('editor_remove_component', 'Remove component'),
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
                title: t('editor_invalid_component', 'Invalid component'),
                message: t('editor_invalid_component_help', 'Please select a valid child component.'),
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
        if (prev && prev.classList.contains('component')) prev.before(comp);
        renumberComponents();
        return;
    }

    // Move down. The Add area is the last child of the same container, and a
    // component never moves below it.
    if (e.target.classList.contains('move-down')) {
        const next = comp.nextElementSibling;
        if (next && next.classList.contains('component')) next.after(comp);
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
function renumberComponents() {
    if (!container) return;

    renumberContainer(container, '');
}

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
// Matches the server-side sanitize_slug(): strip diacritics, then keep
// lowercase letters, digits and dashes.
function slugify(value) {
    return value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim()
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
    if (!el) return;

    new Sortable(el, {
        handle: '.component-title',
        // The Add area is a child too, and must stay last: only components sort.
        draggable: '.component',
        animation: 150,
        ghostClass: 'sortable-ghost',
        onEnd: () => {
            renumberComponents();
        },
    });
}

// bind sortable on initial page load
bindSortable(container);

// ----------------------------
// Add component dialog
// ----------------------------
// Adding is a choice from tiles (label, description, preview) rather than a
// drag from a name-only list. The shared dialog helper owns focus, Escape and
// returning focus to the button that opened it.
const componentPicker = document.getElementById('component-picker');

if (componentPicker) {
    const openers = document.querySelectorAll('[data-modal="component-picker"]');

    openers.forEach(opener => {
        opener.addEventListener('click', () => openDialog(componentPicker, opener));
    });

    componentPicker.querySelectorAll('.close-modal').forEach(button => {
        button.addEventListener('click', () => closeDialog(componentPicker));
    });

    componentPicker.addEventListener('click', event => {
        if (event.target === componentPicker) closeDialog(componentPicker);
    });

    componentPicker.querySelectorAll('[data-component-type]').forEach(tile => {
        tile.addEventListener('click', () => {
            const node = createComponent(tile.dataset.componentType);

            if (node.nodeType !== 1) return;

            appendComponent(node);
            renumberComponents();
            bootstrapQuillEditors(node);
            closeDialog(componentPicker);

            // The editor is looking at the dialog, so show what it added.
            node.scrollIntoView({ block: 'center' });
        });
    });
}

// ----------------------------
// Image Picker Modal Logic
// ----------------------------
const imagePickerModal = document.getElementById('image-picker-modal');
const imageGrid = document.getElementById('image-grid');
const imageSearch = document.getElementById('image-search');
let currentImageField = null;

// Open modal for a given input
function openImagePicker(fieldInput, trigger = null) {
    currentImageField = fieldInput;
    renderImageGrid(window.mediaImages || []);
    openDialog(imagePickerModal, trigger);
    imageSearch.value = '';
}

// Close modal
function closeImagePicker() {
    closeDialog(imagePickerModal);
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
        if (url) el.src = url;
        el.alt = img.original_name;
        el.title = img.original_name;
        el.dataset.id = img.id;

        // Clickable-only would strand keyboard users, so it is also a button.
        el.tabIndex = 0;
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', img.original_name);

        const choose = () => {
            if (!currentImageField) return;

            currentImageField.value = img.id; // save DB id
            showImagePickerPreview(currentImageField);

            closeImagePicker();
        };

        el.addEventListener('click', choose);
        el.addEventListener('keydown', event => {
            if (event.key !== 'Enter' && event.key !== ' ') return;

            event.preventDefault();
            choose();
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
// Attach the picker to image fields that ship their own markup
// ----------------------------
// The component image template and the meta image fields render their own
// buttons, so this only wires them up and shows the current value. A gallery
// row added later re-runs it, so the binding is tracked on the input.
function attachImagePicker() {
    document.querySelectorAll('.field-input[type="text"][data-image-picker]').forEach(input => {
        if (input.dataset.imagePickerBound) return;
        input.dataset.imagePickerBound = '1';

        const wrapper = input.closest('.image-picker-wrapper');

        wrapper?.querySelector('.select-image-btn')?.addEventListener('click', (event) => openImagePicker(input, event.currentTarget));
        wrapper?.querySelector('.clear-image-btn')?.addEventListener('click', () => clearImagePicker(input));

        showImagePickerPreview(input);
    });
}

// Show the value's media in the preview, or the empty state when cleared.
// Clearing drops src entirely so the alt text is what shows.
function showImagePickerPreview(input) {
    const preview = input.closest('.image-picker-wrapper')?.querySelector('.image-preview');
    if (!preview) return;

    const media = (window.mediaImages || []).find(m => String(m.id) === String(input.value));

    if (media) {
        const url = getPreviewUrl(media);

        // A media row with no usable format has nothing to show, and an empty
        // src draws the browser's broken-image placeholder.
        if (url) {
            preview.src = url;
        } else {
            preview.removeAttribute('src');
        }

        preview.alt = media.original_name;
    } else {
        preview.removeAttribute('src');
        // No library match: name whatever the field holds (a theme filename or a
        // URL), so a filled input is not labelled "No image selected".
        preview.alt = input.value || t('media_no_image', 'No image selected');
    }
}

function clearImagePicker(input) {
    input.value = '';
    showImagePickerPreview(input);
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

// ----------------------------
// Icon picker
// ----------------------------
// The browser's tiles carry the inline SVG, so the glyph is copied rather than
// fetched, and the field beside it names the icon the editor chose.
const iconPickerModal = document.getElementById('icon-picker');
let currentIconField = null;

if (iconPickerModal) {
    iconPickerModal.querySelectorAll('[data-icon]').forEach(tile => {
        tile.addEventListener('click', () => chooseIcon(tile.dataset.icon));
    });

    iconPickerModal.querySelectorAll('.close-modal').forEach(button => {
        button.addEventListener('click', () => closeDialog(iconPickerModal));
    });

    iconPickerModal.addEventListener('click', event => {
        if (event.target === iconPickerModal) closeDialog(iconPickerModal);
    });
}

function openIconPicker(fieldInput, trigger = null) {
    currentIconField = fieldInput;
    openDialog(iconPickerModal, trigger);
}

function chooseIcon(name) {
    if (!currentIconField) return;

    currentIconField.value = name;
    showIconPreview(currentIconField);
    closeDialog(iconPickerModal);
    currentIconField = null;
}

function clearIconPicker(input) {
    input.value = '';
    showIconPreview(input);
}

// Paint the stored icon, or say so when there is none. A name the theme does not
// ship (content written for another icon set, or a file the theme dropped) shows
// its name rather than nothing, so it can be corrected.
function showIconPreview(input) {
    const wrapper = input.closest('.icon-picker-wrapper');
    if (!wrapper) return;

    const name = input.value || '';

    wrapper.querySelector('.icon-preview').innerHTML = iconGlyphs[name] || '';
    wrapper.querySelector('.icon-name').textContent = name || t('editor_no_icon', 'No icon');
}

// ----------------------------
// Repeatable gallery meta fields
// ----------------------------
// The rows live in the page markup (they carry the field name), so removing
// one and binding its picker is all this needs to do. Removing every row still
// sends the empty sentinel the markup renders, which clears the field.
function attachGalleryFields() {
    document.querySelectorAll('.add-gallery-image').forEach(btn => {
        if (btn.dataset.galleryBound) return;
        btn.dataset.galleryBound = '1';

        btn.addEventListener('click', () => {
            const rows = document.getElementById(btn.dataset.gallery);
            const template = document.getElementById('gallery-row-template');

            if (!rows || !template) return;

            const row = template.content.firstElementChild.cloneNode(true);
            row.querySelector('input[data-image-picker]').name = rows.dataset.galleryName || '';

            rows.appendChild(row);
            attachImagePicker();
        });
    });
}

document.addEventListener('click', event => {
    const remove = event.target.closest('.remove-gallery-image');

    if (remove) remove.closest('.gallery-row')?.remove();
});

attachGalleryFields();

// ----------------------------
// Autosave and unsaved-changes warning
// ----------------------------
document.addEventListener('DOMContentLoaded', () => {
    const editorForm = document.getElementById('save');

    // An autosave is a version of an existing item; there is nothing to attach
    // one to until the first real save has created the item.
    if (!editorForm || !editorForm.querySelector('input[name="id"]')) return;

    // Compare the whole form with its state on load, so adding, removing or
    // reordering components counts as unsaved work just like typing does.
    const serialize = () => new URLSearchParams(new FormData(editorForm)).toString();
    const initialState = serialize();
    const isDirty = () => serialize() !== initialState;

    let submitting = false;

    // Saving navigates away on purpose; anything else with unsaved edits warns.
    editorForm.addEventListener('submit', () => { submitting = true; });

    window.addEventListener('beforeunload', event => {
        if (submitting || !isDirty()) return;

        event.preventDefault();
        event.returnValue = '';
    });

    async function autosave() {
        if (submitting || !isDirty()) return;

        const body = new FormData(editorForm);
        body.set('autosave', '1');

        try {
            await fetch(editorForm.action, {
                method: 'POST',
                body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
        } catch (error) {
            // A failed autosave must never interrupt editing.
        }
    }

    window.setInterval(autosave, 60000);
});

// ----------------------------
// SEO preview
// ----------------------------
/* The search snippet and the social card follow the fields as they are typed.
   The fallbacks the form cannot show — the site title, the site description and
   the default social image — arrive as data attributes already resolved by
   seo_metadata(); the title chain below mirrors that function's rule. */
const seoPreview = document.querySelector('[data-seo-preview]');

if (seoPreview) {
    const seoField = name => document.getElementById('seo-' + name);
    const pageTitleField = document.getElementById('title');
    const slugField = document.getElementById('slug');

    const fieldValue = el => (el && el.value ? el.value : '').trim();

    const setText = (selector, value) => {
        const el = seoPreview.querySelector(selector);
        if (el) el.textContent = value;
    };

    // The URL a search result shows: the canonical override when there is one,
    // else the address this item is about to have. Shown without the scheme,
    // the way a search result prints it.
    function previewUrl() {
        const canonical = fieldValue(seoField('canonical'));
        const origin = seoPreview.dataset.siteUrl || '';
        const path = (seoPreview.dataset.urlBase || '/') + fieldValue(slugField);

        return (canonical || origin + path).replace(/^https?:\/\//, '');
    }

    function syncPreviewImage() {
        const wrap = seoPreview.querySelector('[data-preview-image]');
        if (!wrap) return;

        const picked = seoField('og_image')
            ?.closest('.image-picker-wrapper')
            ?.querySelector('.image-preview')
            ?.getAttribute('src');

        const src = picked || seoPreview.dataset.defaultImage || '';
        const shown = wrap.querySelector('img');

        if (src !== '') {
            if (shown) {
                shown.src = src;
            } else {
                wrap.replaceChildren(Object.assign(document.createElement('img'), { src, alt: '' }));
            }

            return;
        }

        if (shown) {
            const empty = document.createElement('span');
            empty.className = 'seo-card-image-empty';
            empty.textContent = seoPreview.dataset.noImage || '';

            wrap.replaceChildren(empty);
        }
    }

    function syncSeoPreview() {
        const siteTitle = seoPreview.dataset.siteTitle || '';
        const suffix = seoPreview.dataset.titleSuffix || '';
        const title = fieldValue(pageTitleField);

        let fallbackTitle = siteTitle;

        if (suffix !== '') {
            fallbackTitle = title !== '' ? `${title} ${suffix}` : suffix;
        } else if (title !== '' && seoPreview.dataset.home !== '1') {
            fallbackTitle = `${title} - ${siteTitle}`;
        }

        const seoTitle = fieldValue(seoField('seo_title')) || fallbackTitle;
        const description = fieldValue(seoField('description')) || seoPreview.dataset.defaultDescription || '';
        const url = previewUrl();

        setText('[data-preview-url]', url);
        setText('[data-preview-title]', seoTitle);
        setText('[data-preview-description]', description);
        setText('[data-preview-card-title]', fieldValue(seoField('og_title')) || seoTitle);
        setText('[data-preview-card-description]', fieldValue(seoField('og_description')) || description);
        setText('[data-preview-domain]', url.split('/')[0]);

        syncPreviewImage();
    }

    [pageTitleField, slugField, seoField('seo_title'), seoField('description'), seoField('canonical'), seoField('og_title'), seoField('og_description')]
        .forEach(field => field && field.addEventListener('input', syncSeoPreview));

    // The media picker writes the field itself, so its choice is read after the
    // fact instead of from an event on the field.
    document.getElementById('image-grid')?.addEventListener('click', () => setTimeout(syncSeoPreview, 0));

    seoField('og_image')
        ?.closest('.image-picker-wrapper')
        ?.querySelectorAll('.clear-image-btn')
        .forEach(button => button.addEventListener('click', () => setTimeout(syncSeoPreview, 0)));

    syncSeoPreview();
}

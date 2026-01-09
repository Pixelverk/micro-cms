<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

$pageTitle = 'Add Page';
$username = $_SESSION['user_id'] ?? 'User';

// Load available components from root _components folder
$componentFiles = glob(__DIR__ . '/../_components/*.js');
$availableComponents = array_map(fn($f) => basename($f, '.js'), $componentFiles);

// Exclude site-header/footer
$excluded = ['site-header','site-footer'];
$availableComponents = array_filter($availableComponents, fn($c) => !in_array($c, $excluded));
sort($availableComponents);

// Start with an empty page
$title = '';
$metaDescription = '';
$components = [];

// Optional: validate query param 'slug' if passed, otherwise rely on save
$slug = $_GET['slug'] ?? '';
if ($slug && !preg_match('/^[a-z0-9_-]+$/', $slug)) {
    redirect_with_toast('page-list.php', 'error', 'Invalid slug in URL.');
}

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Welcome, <?= htmlspecialchars($username) ?> 👋</h2>
        <p>Create a new page</p>
    </div>
    <div class="page-actions">
        <button type="submit" form="create">Create Page</button>
    </div>
</div>

<form id="create" method="post" action="page-save.php">
    <label>
        Slug (URL-friendly name):
        <input type="text" name="slug" required>
    </label>

    <!-- Page Info -->
    <fieldset>
        <legend>Page Info</legend>
        <label>
            Title:
            <input type="text" name="title" value="<?= htmlspecialchars($title) ?>" required>
        </label>

        <label>
            Meta Description:
            <textarea name="meta_description"><?= htmlspecialchars($metaDescription) ?></textarea>
        </label>
    </fieldset>

    <!-- Components container -->
    <div id="components-container">
        <!-- Empty initially -->
    </div>

    <!-- Add Top-Level Component -->
    <label>
        Select component to add:
        <select id="new-component-select">
            <option value="">-- Select Component --</option>
            <?php foreach ($availableComponents as $compName): ?>
                <option value="<?= htmlspecialchars($compName) ?>"><?= htmlspecialchars($compName) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="button" id="add-component" class="add-component-btn">Add Component</button>
</form>

<script type="module">
function getMaxPathNumber() {
    const allPaths = Array.from(document.querySelectorAll('.component'))
        .map(fs => fs.dataset.path)
        .flatMap(p => p.split('-').map(Number));
    return allPaths.length ? Math.max(...allPaths) + 1 : 0;
}

let componentCount = getMaxPathNumber();

const container = document.getElementById('components-container');
const availableComponents = <?= json_encode(array_values($availableComponents)) ?>;

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

    // Remove button
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.textContent = '×';
    removeBtn.className = 'remove-btn';
    fs.appendChild(removeBtn);

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

    // Add Child button
    const addChildBtn = document.createElement('button');
    addChildBtn.type = 'button';
    addChildBtn.textContent = 'Add Child Component';
    addChildBtn.className = 'add-child-btn';
    fs.appendChild(addChildBtn);

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
    if (e.target.matches('.remove-btn')) {
        e.target.closest('.component').remove();
        return;
    }

    if (e.target.matches('.add-child-btn')) {
        const fs = e.target.closest('.component');
        const childrenContainer = fs.querySelector('.children-container');
        const childType = prompt("Enter child component type:");
        if (!childType || !availableComponents.includes(childType)) return alert("Invalid component type");

        addComponent(childType, childrenContainer, fs.dataset.path);
        return;
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/_partials/layout.php';
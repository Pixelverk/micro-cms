<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

$pageTitle = 'Edit Page';
$username = $_SESSION['user_id'] ?? 'User';

$slug = $_GET['slug'] ?? '';
if (!$slug) {
    header('Location: page-list.php');
    exit;
}

// Load page JSON
$pageData = load_page($slug);
if (!$pageData) {
    die("Page not found");
}

$title = $pageData['title'] ?? '';
$metaDescription = $pageData['meta']['description'] ?? '';
$components = $pageData['components'] ?? [];

// Load available components from root _components folder
$componentFiles = glob(__DIR__ . '/../_components/*.js');
$availableComponents = array_map(fn($f) => basename($f, '.js'), $componentFiles);

// Exclude site-header/footer
$excluded = ['site-header','site-footer'];
$availableComponents = array_filter($availableComponents, fn($c) => !in_array($c, $excluded));
sort($availableComponents);

// Recursive function to render existing components
function renderComponentFieldset(array $comp, array $availableComponents): string {
    ob_start();
    ?>
    <fieldset class="component" data-path="<?= htmlspecialchars($comp['path'] ?? '') ?>">
        <legend><?= htmlspecialchars($comp['type']) ?></legend>

        <!-- Remove button -->
        <button type="button" class="remove-btn">×</button>

        <input type="hidden" name="components[<?= htmlspecialchars($comp['path'] ?? '') ?>][type]" value="<?= htmlspecialchars($comp['type']) ?>">

        <?php foreach ($comp['props'] ?? [] as $name => $value): ?>
            <label>
                <?= htmlspecialchars($name) ?>:
                <input type="text" name="components[<?= htmlspecialchars($comp['path'] ?? '') ?>][props][<?= htmlspecialchars($name) ?>]" value="<?= htmlspecialchars($value) ?>">
            </label>
        <?php endforeach; ?>

        <!-- Children container -->
        <div class="children-container">
            <?php foreach ($comp['children'] ?? [] as $child): ?>
                <?= renderComponentFieldset($child, $availableComponents) ?>
            <?php endforeach; ?>
        </div>

        <!-- Add Child Button -->
        <button type="button" class="add-child-btn">Add Child Component</button>
    </fieldset>
    <?php
    return ob_get_clean();
}

ob_start();
?>

<style>
h1,h2{margin-bottom:.5rem}
fieldset{border:1px solid #ccc;padding:1rem 1.5rem;margin-bottom:1.5rem;position:relative}
legend{font-weight:bold;padding:0 .5rem}
label{display:block;margin-bottom:.75rem}
input[type=text],textarea,select{width:100%;padding:.5rem;margin-top:.25rem;box-sizing:border-box}
textarea{resize:vertical;min-height:60px}
button{padding:.5rem 1rem;font-size:1rem;background:#00796b;color:white;border:none;border-radius:4px;cursor:pointer;margin-top:.25rem}
button:hover{background:#004d40}
.children-container{margin-left:1.5rem;border-left:2px solid #eee;padding-left:1rem;margin-top:.5rem;}
.add-component-btn{margin-bottom:1rem;}
.remove-btn{position:absolute; top:0.5rem; right:0.5rem; background:#b71c1c;}
</style>

<div class="page-header">
    <div class="page-title">
        <h2>Welcome, <?php echo htmlspecialchars($username); ?> 👋</h2>
        <p>Editing page: <strong><?= htmlspecialchars($slug) ?></strong></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="save">Save Page</button>
    </div>
</div>

<form id="save" method="post" action="page-save.php" id="page-form">
    <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">

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

    <!-- Existing Components -->
    <div id="components-container">
        <?php
        $componentIndex = 0;
        function assignPaths(array &$comps, string $prefix = '') {
            foreach ($comps as $i => &$comp) {
                $path = $prefix === '' ? (string)$i : $prefix . '-' . $i;
                $comp['path'] = $path;
                if (!empty($comp['children'])) {
                    assignPaths($comp['children'], $path);
                }
            }
        }
        assignPaths($components);

        foreach ($components as $comp) {
            echo renderComponentFieldset($comp, $availableComponents);
        }
        ?>
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
const availableComponents = ["cta-section","feature-card","features-section","hero-section"];

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
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/_partials/layout.php';
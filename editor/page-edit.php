<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Path to site pages (relative to editor folder)
$pagesRoot = realpath(__DIR__ . '/../'); // assuming editor/ is inside project root

$pages = [];

// Scan subfolders for index.html
foreach (scandir($pagesRoot) as $item) {
    if ($item === '.' || $item === '..' || $item === '_components' || $item === '_assets' || $item === '_vendor' || $item === 'editor') continue;
    $path = $pagesRoot . '/' . $item . '/index.html';
    if (file_exists($path)) {
        $pages[] = $item;
    }
}

// Select the page
$page = $_GET['page'] ?? ($pages[0] ?? '');
$pageFile = $pagesRoot . '/' . $page . '/index.html';

// Load page HTML
$html = file_exists($pageFile) ? file_get_contents($pageFile) : '';
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
libxml_clear_errors();

// Get <title>
$titleTag = $dom->getElementsByTagName('title')->item(0);
$titleValue = $titleTag ? $titleTag->nodeValue : '';

// Get <meta name="description">
$metaDescValue = '';
foreach ($dom->getElementsByTagName('meta') as $meta) {
    if (strtolower($meta->getAttribute('name')) === 'description') {
        $metaDescValue = $meta->getAttribute('content');
        break;
    }
}

// Find all custom elements
$components = [];
foreach ($dom->getElementsByTagName('*') as $el) {
    if (strpos($el->tagName, '-') !== false) {
        $components[] = $el;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Page Editor - <?php echo htmlspecialchars($page); ?></title>
    <style>
        body { font-family: sans-serif; margin: 2rem; }
        fieldset { margin-bottom: 2rem; padding: 1rem; }
        label { display: block; margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <h1>Page Editor: <?php echo htmlspecialchars($page); ?></h1>
    <form method="get" id="page-select-form">
        <label>
            Select page:
            <select name="page" onchange="document.getElementById('page-select-form').submit()">
                <?php foreach ($pages as $p): ?>
                    <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $page ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <p><a href="logout.php">Logout</a></p>

    <form action="save.php" method="post">
        <input type="hidden" name="page" value="<?php echo htmlspecialchars($page); ?>">

        <fieldset>
            <legend>Page Metadata</legend>
            <label>
                Title:
                <input type="text" name="title" value="<?php echo htmlspecialchars($titleValue); ?>">
            </label>
            <label>
                Meta Description:
                <textarea name="meta_description" rows="3"><?php echo htmlspecialchars($metaDescValue); ?></textarea>
            </label>
        </fieldset>

        <?php foreach ($components as $i => $comp): ?>
            <fieldset>
                <legend><?php echo $comp->tagName; ?> (component <?php echo $i+1; ?>)</legend>
                <?php
                foreach ($comp->attributes as $attr) {
                    $name = htmlspecialchars($attr->name);
                    $value = htmlspecialchars($attr->value);
                    echo "<label>$name: <input type='text' name='components[$i][$name]' value='$value'></label>";
                }
                ?>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit">Save Changes</button>
    </form>
</body>
</html>
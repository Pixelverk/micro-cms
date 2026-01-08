<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

$slug = $_GET['slug'] ?? '';
if (!$slug) {
    header('Location: page-list.php');
    exit;
}

// Load page HTML
$html = load_page($slug);
if (!$html) {
    die("Page not found");
}

libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML($html);

// ----------------------------
// Get <title>
$titleNodes = $doc->getElementsByTagName('title');
$title = $titleNodes->length ? $titleNodes->item(0)->textContent : '';

// ----------------------------
// Get meta description
$metaDescription = '';
foreach ($doc->getElementsByTagName('meta') as $meta) {
    if (strtolower($meta->getAttribute('name')) === 'description') {
        $metaDescription = $meta->getAttribute('content');
        break;
    }
}

// ----------------------------
// Collect components (flat array)
$components = [];
foreach ($doc->getElementsByTagName('*') as $el) {
    if (strpos($el->tagName, '-') !== false) {
        $attrs = [];
        foreach ($el->attributes as $attr) {
            $attrs[$attr->name] = $attr->value;
        }
        $components[] = [
            'tag' => $el->tagName,
            'attributes' => $attrs
        ];
    }
}

$username = $_SESSION['user_id'] ?? 'User';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Editor - Edit Page: <?= htmlspecialchars($slug) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="_assets/style.css">
    <style>
        h1, h2 { margin-bottom: 0.5rem; }
        form { max-width: 800px; margin-top: 1rem; }
        fieldset { border: 1px solid #ccc; padding: 1rem 1.5rem; margin-bottom: 1.5rem; }
        legend { font-weight: bold; padding: 0 0.5rem; }
        label { display: block; margin-bottom: 0.75rem; }
        input[type="text"], textarea { width: 100%; padding: 0.5rem; margin-top: 0.25rem; box-sizing: border-box; }
        textarea { resize: vertical; min-height: 60px; }
        button { padding: 0.75rem 1.5rem; font-size: 1rem; background: #00796b; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #004d40; }
    </style>
</head>
<body>

<header>
    <h1>Micro CMS - Edit Page</h1>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="page-list.php">Pages</a>
        <a href="user-list.php">Users</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main>
    <h2>Hello, <?= htmlspecialchars($username) ?> 👋</h2>
    <p>Editing page: <strong><?= htmlspecialchars($slug) ?></strong></p>

    <form method="post" action="page-save.php">
        <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">

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

        <?php foreach ($components as $i => $comp): ?>
            <fieldset>
                <legend>Component: <?= htmlspecialchars($comp['tag']) ?></legend>
                <input type="hidden" name="components[<?= $i ?>][tag]" value="<?= htmlspecialchars($comp['tag']) ?>">

                <?php foreach ($comp['attributes'] as $name => $value): ?>
                    <label>
                        <?= htmlspecialchars($name) ?>:
                        <input type="text" name="components[<?= $i ?>][attributes][<?= htmlspecialchars($name) ?>]" value="<?= htmlspecialchars($value) ?>">
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit">Save Page</button>
    </form>
</main>

</body>
</html>
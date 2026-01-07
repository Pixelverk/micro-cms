<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$page = $_POST['page'] ?? '';
$pageFile = "../$page/index.html";
$componentsData = $_POST['components'] ?? [];
$newTitle = $_POST['title'] ?? '';
$newMetaDescription = $_POST['meta_description'] ?? '';

if (!file_exists($pageFile)) {
    die("Page not found");
}

// Load HTML
$html = file_get_contents($pageFile);
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
libxml_clear_errors();

// Update <title>
$titleTag = $dom->getElementsByTagName('title')->item(0);
if ($titleTag) {
    $titleTag->nodeValue = $newTitle;
}

// Update <meta name="description">
$metaUpdated = false;
foreach ($dom->getElementsByTagName('meta') as $meta) {
    if (strtolower($meta->getAttribute('name')) === 'description') {
        $meta->setAttribute('content', $newMetaDescription);
        $metaUpdated = true;
        break;
    }
}
// If meta description didn’t exist, create it
if (!$metaUpdated) {
    $head = $dom->getElementsByTagName('head')->item(0);
    if ($head) {
        $newMeta = $dom->createElement('meta');
        $newMeta->setAttribute('name', 'description');
        $newMeta->setAttribute('content', $newMetaDescription);
        $head->appendChild($newMeta);
    }
}

// Update component attributes (same as before)
$compElements = [];
foreach ($dom->getElementsByTagName('*') as $el) {
    if (strpos($el->tagName, '-') !== false) {
        $compElements[] = $el;
    }
}

foreach ($componentsData as $i => $attrs) {
    if (!isset($compElements[$i])) continue;
    foreach ($attrs as $name => $value) {
        $compElements[$i]->setAttribute($name, $value);
    }
}

// Save updated HTML
file_put_contents($pageFile, $dom->saveHTML());

header("Location: index.php?page=$page");
exit;

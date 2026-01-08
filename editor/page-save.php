<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

// Get POST data
$slug = $_POST['slug'] ?? '';
if (!$slug) {
    header('Location: page-list.php');
    exit;
}

// Load current HTML
$html = load_page($slug);

libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML($html);

// ----------------------------
// Update <title>
if (isset($_POST['title'])) {
    $titleNodes = $doc->getElementsByTagName('title');
    if ($titleNodes->length) {
        $titleNodes->item(0)->textContent = $_POST['title'];
    } else {
        $head = $doc->getElementsByTagName('head')->item(0);
        $titleEl = $doc->createElement('title', $_POST['title']);
        $head->appendChild($titleEl);
    }
}

// ----------------------------
// Update <meta name="description">
if (isset($_POST['meta_description'])) {
    $metaUpdated = false;
    foreach ($doc->getElementsByTagName('meta') as $meta) {
        if ($meta->getAttribute('name') === 'description') {
            $meta->setAttribute('content', $_POST['meta_description']);
            $metaUpdated = true;
            break;
        }
    }
    if (!$metaUpdated) {
        $head = $doc->getElementsByTagName('head')->item(0);
        $metaEl = $doc->createElement('meta');
        $metaEl->setAttribute('name', 'description');
        $metaEl->setAttribute('content', $_POST['meta_description']);
        $head->appendChild($metaEl);
    }
}

// ----------------------------
// Update component attributes (flat array approach)
if (isset($_POST['components']) && is_array($_POST['components'])) {
    // Collect all component elements (tags with dash)
    $compElements = [];
    foreach ($doc->getElementsByTagName('*') as $el) {
        if (strpos($el->tagName, '-') !== false) {
            $compElements[] = $el;
        }
    }

    // Update attributes
    foreach ($_POST['components'] as $i => $attrs) {
        if (!isset($compElements[$i])) continue;
        foreach ($attrs['attributes'] ?? [] as $name => $value) {
            $compElements[$i]->setAttribute($name, $value);
        }
    }
}

// ----------------------------
// Save updated HTML
// Use DOMDocument->saveHTML but preserve UTF-8
$newHtml = $doc->saveHTML();
save_page($slug, $newHtml);

libxml_clear_errors();

// Redirect back to the edit page
header("Location: page-edit.php?slug=" . urlencode($slug) . "&saved=1");
exit;
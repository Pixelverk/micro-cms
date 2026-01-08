<?php
declare(strict_types=1);

// Front controller / router

// Define pages folder
$pagesDir = __DIR__ . '/pages';

// Get the requested path, remove query string
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Redirect to trailing slash if it's missing
if ($path !== '/' && substr($path, -1) !== '/') {
    header("Location: $path/", true, 301);
    exit;
}

// Normalize slug (remove leading/trailing slashes)
$slug = trim($path, '/');

// Default to 'home' if empty 
if ($slug === '') {
    $slug = 'home';
}

// Map slug to page folder
$pageFile = $pagesDir . '/' . $slug . '/index.html';

// Check if file exists
if (file_exists($pageFile)) {
    // Serve the HTML file
    header('Content-Type: text/html; charset=utf-8');
    readfile($pageFile);
    exit;
}

// Optional: 404 page
if (file_exists($pagesDir . '/404/index.html')) {
    // Serve the HTML file
    readfile($pagesDir . '/404/index.html');
    exit;
} else {
    header("HTTP/1.0 404 Not Found");
    echo "<h1>404 Not Found</h1>";
    echo "<p>The page '$slug' does not exist.</p>";
    exit;
}
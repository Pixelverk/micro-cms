<?php

function serveMedia($request){

    // Strip "/media" prefix
    $relative = substr($request, strlen('/media'));
    $relative = ltrim($relative, '/');

    // Block traversal
    if ($relative === '' || str_contains($relative, '..')) {
        http_response_code(400);
        exit;
    }

    $file = STORAGE_PATH . '/media/' . $relative;

    if (!is_file($file)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . mime_content_type($file));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=31536000');
    readfile($file);
}


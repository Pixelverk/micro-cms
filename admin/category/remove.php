<?php
declare(strict_types=1);

// ----------------------------
// Only allow POST
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

remove_taxonomy('category', (int) ($_POST['id'] ?? 0));

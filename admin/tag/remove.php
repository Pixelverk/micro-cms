<?php
declare(strict_types=1);

// ----------------------------
// Only allow POST
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(admin_trans('error_method'));
}

remove_taxonomy('tag', (int) ($_POST['id'] ?? 0));

<?php
declare(strict_types=1);

// Delete a term of the taxonomy named by ?type=.
$type = (string) ($_GET['type'] ?? '');

if (taxonomy_config($type) === null) {
    redirect_with_toast('dashboard', 'error', admin_trans('taxonomy_error_not_found', ['label' => 'Taxonomy']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(admin_trans('error_method'));
}

remove_taxonomy($type, (int) ($_POST['id'] ?? 0));

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fresh signed form token
|--------------------------------------------------------------------------
| Pages are cached for up to cache_lifetime, so an embedded token can expire
| while the HTML stays valid. The contact form calls this endpoint when the
| server rejects a stale token, then retries once.
|
| Public, read-only, no session state — safe to expose.
|
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$theme     = theme_config();
$formTypes = $theme['form_types'] ?? [];

$formType = isset($_GET['form_type']) ? (string) $_GET['form_type'] : '';

if ($formType === '' || !isset($formTypes[$formType])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid form type']);
    exit;
}

echo json_encode([
    'form_type' => $formType,
    'field'     => form_token_field($formType),
    'token'     => form_token($formType),
]);

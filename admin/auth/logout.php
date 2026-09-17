<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
| POST only — the admin bootstrap already verified the CSRF token.
|
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

logout();
redirect('login');
exit();

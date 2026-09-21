<?php
declare(strict_types=1);



/*
|--------------------------------------------------------------------------
| Maintenance mode
|--------------------------------------------------------------------------
|
| A Settings toggle that closes the public site while the admin stays open.
| The 503 path deliberately depends on nothing but the settings and e(): during
| an upgrade the theme or content tables may be mid-change.
|
*/

/**
 * Is the public site closed for maintenance?
 */
function maintenance_mode_enabled(): bool
{
    return (bool) get_setting('maintenance_mode', false);
}


/**
 * The message visitors see while the site is closed.
 */
function maintenance_message(): string
{
    $message = trim((string) get_setting('maintenance_message', ''));

    return $message !== '' ? $message : 'We are doing a bit of maintenance and will be back shortly.';
}


/**
 * Send the maintenance 503 and stop.
 *
 * Never cached, and Retry-After tells browsers and crawlers when to look again.
 */
function serve_maintenance_response(): void
{
    http_response_code(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Content-Type: text/html; charset=utf-8');

    $title = (string) get_setting('site_title', 'Micro CMS');

    echo '<!DOCTYPE html><html lang="' . e(get_setting('site_language', 'en')) . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<meta name="robots" content="noindex, follow">';
    echo '<title>' . e($title) . '</title>';
    echo '<style>body{font:16px/1.6 system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1.5rem;color:#1f2937}';
    echo 'h1{font-size:1.3rem;margin:0 0 .5rem}p{margin:0}</style>';
    echo '</head><body><h1>' . e($title) . '</h1><p>' . e(maintenance_message()) . '</p></body></html>';

    exit;
}

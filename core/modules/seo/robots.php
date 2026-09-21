<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| robots.txt
|--------------------------------------------------------------------------
|
| Served virtually, like WordPress does, so a crawler always finds the
| sitemap without a file to deploy or keep writable. The defaults allow
| everything; Settings can append extra lines verbatim.
|
*/

/**
 * The robots.txt body.
 *
 * The extra lines are passed through untouched: parsing them would mean
 * owning the robots.txt grammar, and a crawler is a more forgiving reader
 * than any validator this CMS could ship.
 */
function robots_txt(): string
{
    $lines = [
        'User-agent: *',
        'Allow: /',
        '',
        'Sitemap: ' . site_origin() . '/sitemap.xml',
    ];

    // A textarea submits CRLF; normalise so the served body is consistent.
    $extra = trim(str_replace(["\r\n", "\r"], "\n", (string) get_setting('robots_extra', '')));

    if ($extra !== '') {
        $lines[] = '';
        $lines[] = $extra;
    }

    return implode("\n", $lines) . "\n";
}

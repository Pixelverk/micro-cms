<?php
declare(strict_types=1);



/**
 * The site logo: the Settings value, else the theme manifest, else nothing.
 */
function site_logo_url(): string
{
    $value = trim((string) get_setting('logo', ''));

    if ($value !== '') {
        return resolve_image_value($value);
    }

    $fallback = (string) (theme_config()['icons']['logo'] ?? '');

    return $fallback !== '' ? asset($fallback) : '';
}


/**
 * The favicon: the Settings value, else the theme manifest, else nothing.
 */
function site_favicon_url(): string
{
    $value = trim((string) get_setting('favicon', ''));

    if ($value !== '') {
        return resolve_image_value($value);
    }

    $fallback = (string) (theme_config()['icons']['favicon'] ?? '');

    return $fallback !== '' ? asset($fallback) : '';
}

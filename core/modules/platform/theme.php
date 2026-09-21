<?php
declare(strict_types=1);



/*
|--------------------------------------------------------------------------
| Theme Helpers
|--------------------------------------------------------------------------
*/

function theme(string $path = ''): string
{
    $base = CMS_PATH . '/theme';
    return $path ? $base . '/' . ltrim($path, '/') : $base;
}


function theme_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $themeFile = theme('theme.php');

    if (!file_exists($themeFile)) {
        throw new RuntimeException("Theme config not found at {$themeFile}");
    }

    $config = require $themeFile;

    if (!is_array($config)) {
        throw new RuntimeException("Theme config at {$themeFile} must return an array");
    }

    return $config;
}


/**
 * Stamp an asset URL with the file's modification time.
 *
 * Editing the file changes its URL, so a browser holding the old one fetches
 * the new copy instead of serving a stale stylesheet or script from its cache.
 * A file that is not there is left unstamped: a version that points at nothing
 * helps no one.
 */
function version_asset_url(string $url, string $file): string
{
    return is_file($file) ? $url . '?v=' . filemtime($file) : $url;
}


/**
 * Return a URL to a theme asset, respecting base URL and subfolder.
 * External assets are identified by https.
 */
function asset(string $path): string
{
    if (preg_match('#^(?:https?:)?//#i', $path)) {
        return $path;
    }

    // A query string is not part of the file name. Dropping it means a
    // hand-written ?v= is replaced by the stamp rather than appended to.
    $relative = ltrim(explode('?', $path, 2)[0], '/');

    return version_asset_url(
        url('theme/assets/' . $relative),
        CMS_PATH . '/theme/assets/' . $relative
    );
}

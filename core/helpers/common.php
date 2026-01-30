<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Config Access
|--------------------------------------------------------------------------
*/

function config(string $key, mixed $default = null): mixed
{
    $config = require CMS_PATH . '/config.php';

    $segments = explode('.', $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

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

/*
|--------------------------------------------------------------------------
| HTML Escaping
|--------------------------------------------------------------------------
*/

function e(string|int|null $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| JSON Helpers
|--------------------------------------------------------------------------
*/

function json_decode_safe(string $json): array
{
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function json_encode_safe(mixed $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}


/** DUMP & DIE */
function dd($obj) {
    highlight_string("<?php\n" . print_r($obj, true) . "\n", false);
    die;
}

/**
 * Generate a full URL for the site, respecting subfolder deployment.
 *
 * Examples:
 *   url()                  => "/" or "/subfolder/"
 *   url("admin/dashboard") => "/admin/dashboard/" or "/subfolder/admin/dashboard/"
 *
 * @param string $path Relative path or file
 * @return string
 */
function url(string $path = ''): string
{
    global $config;

    // Base URL from config — set '' for root, '/subfolder' if deployed in a subfolder
    $baseUrl = rtrim($config['url'] ?? '', '/');

    // Remove leading slash to avoid double slashes
    $path = ltrim($path, '/');

    // If no path, just return base URL with trailing slash
    if ($path === '') {
        return $baseUrl === '' ? '/' : $baseUrl . '/';
    }

    // Detect files by extension (don't add trailing slash)
    $isFile = pathinfo($path, PATHINFO_EXTENSION) !== '';

    // Compose URL
    $url = $baseUrl === '' ? '/' . $path : $baseUrl . '/' . $path;

    // Add trailing slash if it's a directory
    if (!$isFile) {
        $url = rtrim($url, '/') . '/';
    }

    return $url;
}

// Slugify string
function sanitize_slug(string $slug): string {
    // Convert to lowercase
    $slug = strtolower($slug);
    // Replace spaces and underscores with dashes
    $slug = preg_replace('/[\s_]+/', '-', $slug);
    // Remove all characters except letters, numbers, and dashes
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    // Remove multiple consecutive dashes
    $slug = preg_replace('/-+/', '-', $slug);
    // Trim leading/trailing dashes
    $slug = trim($slug, '-');
    return $slug;
}

/**
 * Return a URL to a theme asset, respecting base URL and subfolder.
 */
function asset(string $path): string
{
    return url("theme/assets/" . ltrim($path, '/'));
}

/**
 * Return a URL to a theme image, respecting base URL and subfolder.
 */
function img(string $path): string
{
    return url("theme/assets/img/" . ltrim($path, '/'));
}

// Debug log message
function debug_log(string $msg): void {
    $file = STORAGE_PATH . '/logs/debug.log';
    $time = date('Y-m-d H:i:s');
    file_put_contents($file, "[{$time}] {$msg}\n", FILE_APPEND);
}
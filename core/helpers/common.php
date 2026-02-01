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


// login status? 
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login');
        exit;
    }
}

// timezone convert
function format_local_datetime(?int $timestamp, string $format = 'Y-m-d H:i'): string
{
    if (!$timestamp) {
        return '—';
    }

    $dt = new DateTime('@' . $timestamp); // UTC
    $dt->setTimezone(new DateTimeZone(SITE_TIMEZONE));

    return $dt->format($format);
}

/**
 * Return a picture element containing data for an uploaded image with the given id.
 */
function picture(int $id, array $attrs = []): string
{
    if ($id <= 0) return '';

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM media WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return '';

    $formats = json_decode($row['formats_json'] ?? '{}', true) ?? [];
    $filePath = $row['file_path'] ?? '';
    if (!$formats && !$filePath) return '';

    $width  = (int)($row['width'] ?? 0);
    $height = (int)($row['height'] ?? 0);
    $lqip   = $row['lqip_base64'] ?? '';

    // -------------------------
    // build srcset helper
    // -------------------------
    $buildSrcset = function(array $paths) use ($filePath, $width) {
        $items = [];

        if (empty($paths) && $filePath) {
            // no variants → use original file
            $items[$width ?: 0] = url('media/' . $filePath);
        } else {
            foreach ($paths as $path) {
                if (preg_match('/\/(\d+)\./', $path, $m)) {
                    $items[(int)$m[1]] = url('media/' . $path);
                } else {
                    // fallback if width not in filename
                    $items[$width ?: 0] = url('media/' . $path);
                }
            }
        }

        ksort($items);
        return $items;
    };

    // fallback format (first non-webp)
    $fallbackFormat = null;
    foreach ($formats as $fmt => $paths) {
        if ($fmt !== 'webp') {
            $fallbackFormat = $fmt;
            break;
        }
    }
    if (!$fallbackFormat && $filePath) {
        $fallbackFormat = pathinfo($filePath, PATHINFO_EXTENSION);
    }
    if (!$fallbackFormat) return '';

    $fallbackSet = $buildSrcset($formats[$fallbackFormat] ?? []);
    if (!$fallbackSet && $filePath) {
        $fallbackSet = [$width ?: 0 => url('media/' . $filePath)];
    }
    $fallbackSrc = reset($fallbackSet);
    $fallbackSrcset = implode(', ', array_map(fn($url, $w) => "{$url} {$w}w", $fallbackSet, array_keys($fallbackSet)));

    $webpSet = !empty($formats['webp']) ? $buildSrcset($formats['webp']) : [];
    $webpSrcset = implode(', ', array_map(fn($url, $w) => "{$url} {$w}w", $webpSet, array_keys($webpSet)));

    // -------------------------
    // default attributes
    // -------------------------
    $attrs['loading'] ??= 'lazy';
    $attrs['alt'] ??= $row['alt_text'] ?? '';
    if ($width && $height) {
        $attrs['width'] ??= $width;
        $attrs['height'] ??= $height;
    }

    $attrString = '';
    foreach ($attrs as $k => $v) {
        $attrString .= ' ' . e($k) . '="' . e((string)$v) . '"';
    }

    // -------------------------
    // set initial sizes to smallest width
    $smallestWidth = (int)array_key_first($fallbackSet);
    $initialSizes = $smallestWidth ? $smallestWidth . 'px' : '100vw';

    // -------------------------
    // build html
    // -------------------------
    $html = '<div class="image-wrapper"';
    if ($lqip) {
        $html .= ' style="background-image:url(' . e($lqip) . ');">';
    }

    $html .= '<picture>';

    if ($webpSet) {
        $html .= '<source type="image/webp" srcset="' . e($webpSrcset) . '" sizes="' . e($initialSizes) . '">';
    }

    $html .= '<img src="' . e($fallbackSrc) . '" srcset="' . e($fallbackSrcset) . '" sizes="' . e($initialSizes) . '"' . $attrString . '>';
    $html .= '</picture>';
    $html .= '</div>';

    return $html;
}
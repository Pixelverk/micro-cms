<?php
// editor/_core/bootstrap.php
declare(strict_types=1);

// --------------------------------------------------
// Load config
// --------------------------------------------------
$config = require __DIR__ . '/../../config.php';

// --------------------------------------------------
// Load settings helpers
// --------------------------------------------------
require __DIR__ . '/settings.php';

// --------------------------------------------------
// Error reporting
// --------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', $config['env'] === 'local' ? '1' : '0');

// --------------------------------------------------
// Sessions
// --------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session timeout
if (!empty($_SESSION['login_time']) && time() - $_SESSION['login_time'] > $config['session']['timeout']) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// --------------------------------------------------
// Path constants (derived from config)
// --------------------------------------------------
define('PROJECT_ROOT',    $config['paths']['project_root']);
define('EDITOR_ROOT',     $config['paths']['editor_root']);
define('PAGES_DIR',       $config['paths']['pages']);
define('ASSETS_DIR',      $config['paths']['assets']);
define('COMPONENTS_DIR',  $config['paths']['components']);

// --------------------------------------------------
// Includes
// --------------------------------------------------
require_once __DIR__ . '/auth.php';

// --------------------------------------------------
// Helper functions
// --------------------------------------------------
function redirect(string $path): void
{
    header('Location: ' . url('editor/' . $path));
    exit;
}

function redirect_with_toast(string $url, string $type, string $message): void
{
    $_SESSION['toast'] = [
        'type'    => $type,
        'message' => $message,
    ];
    header('Location: ' . url('editor/' . $url));
    exit;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

// HTML escape helper
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// URL helper
function url(string $path = ''): string
{
    global $config; // reuse already loaded config
    $baseUrl = rtrim($config['url'], '/');

    $path = ltrim($path, '/');

    if ($path === '') {
        return $baseUrl . '/';
    }

    // Detect file extensions (no trailing slash)
    if (pathinfo($path, PATHINFO_EXTENSION)) {
        return $baseUrl . '/' . $path;
    }

    return $baseUrl . '/' . rtrim($path, '/') . '/';
}

/**
 * Make slugs look like we want
 */
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
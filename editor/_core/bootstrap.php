<?php
// editor/_core/bootstrap.php

declare(strict_types=1);

// --------------------------------------------------
// Load config
// --------------------------------------------------
$config = require __DIR__ . '/config.php';

// --------------------------------------------------
// Error reporting
// --------------------------------------------------
error_reporting(E_ALL);

if ($config['env'] === 'local') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

// --------------------------------------------------
// Sessions
// --------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session timeout
if (!empty($_SESSION['login_time'])) {
    if (time() - $_SESSION['login_time'] > $config['session']['timeout']) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

// --------------------------------------------------
// Path constants (derived from config)
// --------------------------------------------------
define('PROJECT_ROOT', $config['paths']['project_root']);
define('EDITOR_ROOT',  $config['paths']['editor_root']);
define('PAGES_DIR',    $config['paths']['pages']);
define('ASSETS_DIR',   $config['paths']['assets']);
define('COMPONENTS_DIR', $config['paths']['components']);

// --------------------------------------------------
// Includes
// --------------------------------------------------
require_once __DIR__ . '/auth.php';

// --------------------------------------------------
// Helper functions
// --------------------------------------------------

function redirect(string $path): void
{
    header('Location: /editor/' . $path);
    exit;
}

function redirect_with_toast(string $url, string $type, string $message): void
{
    $_SESSION['toast'] = [
        'type'    => $type,
        'message' => $message,
    ];
    header("Location: /editor/" . $url);
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

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
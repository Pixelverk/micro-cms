<?php
// core/auth.php

declare(strict_types=1);

// --------------------------------------------------
// Load users
// --------------------------------------------------

function load_users(): array
{
    if (!file_exists(USER_FILE)) {
        return [];
    }

    $json = file_get_contents(USER_FILE);
    $users = json_decode($json, true);

    return is_array($users) ? $users : [];
}

// --------------------------------------------------
// Save users
// --------------------------------------------------

function save_users(array $users): void
{
    file_put_contents(
        USER_FILE,
        json_encode($users, JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

// --------------------------------------------------
// Authentication
// --------------------------------------------------

function login(string $username, string $password): bool
{
    $users = load_users();

    if (!isset($users[$username])) {
        return false;
    }

    $hash = $users[$username]['password'] ?? '';

    if (!password_verify($password, $hash)) {
        return false;
    }

    // Regenerate session ID on login (important)
    session_regenerate_id(true);

    $_SESSION['user_id'] = $username;
    $_SESSION['login_time'] = time();

    return true;
}

function logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

// --------------------------------------------------
// User management helpers
// --------------------------------------------------

function create_user(string $username, string $password): void
{
    $users = load_users();

    $users[$username] = [
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'created'  => time(),
    ];

    save_users($users);
}

function user_exists(string $username): bool
{
    $users = load_users();
    return isset($users[$username]);
}

function current_user(): ?string
{
    return $_SESSION['user_id'] ?? null;
}

// create a demo user if no user exists
if (config('env') === 'local' && count(load_users()) < 1) {
    create_user('demo', 'demo');
}


// --------------------------------------------------
// Login helpers
// --------------------------------------------------

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

// --------------------------------------------------
// Session timeout
// --------------------------------------------------
function session_timeout_check(): void
{
    $timeout = config('session.timeout');

    if (
        $timeout !== null &&
        !empty($_SESSION['login_time']) &&
        time() - $_SESSION['login_time'] > $timeout
    ) {
        logout();
        redirect('login');
    }
}
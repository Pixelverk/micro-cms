<?php
declare(strict_types=1);

// --------------------------------------------------
// Authentication & User Management (SQLite)
// --------------------------------------------------

/**
 * Get all users from the database.
 * Returns array keyed by username for convenience.
 */
function load_users(): array
{
    $pdo = db();

    $stmt = $pdo->query("SELECT * FROM users");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $users = [];
    foreach ($rows as $row) {
        $users[$row['username']] = $row;
    }

    return $users;
}

/**
 * Save a new or updated user to the database.
 */
function save_user(array $user): void
{
    $pdo = db();

    if (!empty($user['id'])) {
        // Update existing user
        $stmt = $pdo->prepare("
            UPDATE users
            SET username = :username,
                first_name = :first_name,
                last_name = :last_name,
                email = :email,
                password_hash = :password_hash,
                last_login = :last_login
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $user['id'],
            'username' => $user['username'],
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'email' => $user['email'] ?? null,
            'password_hash' => $user['password_hash'],
            'last_login' => $user['last_login'] ?? null,
        ]);
    } else {
        // Insert new user
        $stmt = $pdo->prepare("
            INSERT INTO users (username, first_name, last_name, email, password_hash, created_at)
            VALUES (:username, :first_name, :last_name, :email, :password_hash, :created_at)
        ");
        $stmt->execute([
            'username' => $user['username'],
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'email' => $user['email'] ?? null,
            'password_hash' => $user['password_hash'],
            'created_at' => time(),
        ]);
    }
}

// --------------------------------------------------
// Authentication
// --------------------------------------------------

function login(string $username, string $password): bool
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Regenerate session ID on login (important)
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['username'];
    $_SESSION['login_time'] = time();

    // Update last login
    $stmt = $pdo->prepare("UPDATE users SET last_login = :last_login WHERE id = :id");
    $stmt->execute([
        'last_login' => time(),
        'id' => $user['id'],
    ]);

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
// User helpers
// --------------------------------------------------

function create_user(string $username, string $password, ?string $firstName = null, ?string $lastName = null, ?string $email = null): void
{
    $hashed = password_hash($password, PASSWORD_DEFAULT);

    save_user([
        'username' => $username,
        'password_hash' => $hashed,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
    ]);
}

function user_exists(string $username): bool
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    return (bool)$stmt->fetchColumn();
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// --------------------------------------------------
// Login status
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
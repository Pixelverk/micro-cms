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

    if (empty($user['username'])) {
        throw new RuntimeException('save_user() requires a username.');
    }

    if (!empty($user['id'])) {
        // Update existing user. An omitted password_hash means "keep the
        // current one" rather than "blank it".
        $passwordHash = $user['password_hash'] ?? null;

        if ($passwordHash === null || $passwordHash === '') {
            $current = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
            $current->execute(['id' => $user['id']]);
            $passwordHash = (string) $current->fetchColumn();

            if ($passwordHash === '') {
                throw new RuntimeException('Refusing to save a user without a password hash.');
            }
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET username = :username,
                first_name = :first_name,
                last_name = :last_name,
                email = :email,
                role = :role,
                password_hash = :password_hash,
                ui_language = :ui_language,
                last_login = :last_login
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $user['id'],
            'username' => $user['username'],
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => in_array($user['role'] ?? '', admin_roles(), true) ? $user['role'] : 'author',
            'password_hash' => $passwordHash,
            'ui_language' => $user['ui_language'] ?? null,
            'last_login' => $user['last_login'] ?? null,
        ]);
    } else {
        // Insert new user
        if (empty($user['password_hash'])) {
            throw new RuntimeException('save_user() requires a password hash for a new user.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (username, first_name, last_name, email, role, password_hash, created_at)
            VALUES (:username, :first_name, :last_name, :email, :role, :password_hash, :created_at)
        ");
        $stmt->execute([
            'username' => $user['username'],
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => in_array($user['role'] ?? '', admin_roles(), true) ? $user['role'] : 'author',
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
        if (function_exists('throttle_fail')) {
            throttle_fail($username);
            log_activity('user.login_failed', 'user', null, $username, []);
        }
        return false;
    }

    // A valid password clears any pending lockout counter.
    if (function_exists('throttle_clear')) {
        throttle_clear($username);
    }

    // Regenerate session ID on login (important)
    session_regenerate_id(true);

    // Identity is the stable numeric id; the username is display/test data only.
    $_SESSION['user_id']  = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['login_time'] = time();

    // A fresh preview token per login, kept in its own cookie so it survives
    // the session-id regeneration above.
    if (function_exists('preview_token_issue')) {
        preview_token_issue();
    }

    unset($_SESSION['csrf_token']);

    // Update last login
    $stmt = $pdo->prepare("UPDATE users SET last_login = :last_login WHERE id = :id");
    $stmt->execute([
        'last_login' => time(),
        'id'         => $user['id'],
    ]);

    if (function_exists('log_activity')) {
        log_activity('user.login', 'user', (int) $user['id'], $user['username'], []);
    }

    return true;
}

function logout(): void
{
    $userId = current_user_id();
    $username = current_username();

    if ($userId && function_exists('log_activity')) {
        log_activity('user.logout', 'user', $userId, $username, []);
    }

    if (function_exists('preview_token_clear')) {
        preview_token_clear();
    }

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

function create_user(string $username, string $password, ?string $firstName = null, ?string $lastName = null, ?string $email = null, string $role = 'author'): void
{
    $hashed = password_hash($password, PASSWORD_DEFAULT);

    save_user([
        'username' => $username,
        'password_hash' => $hashed,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'role' => $role,
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

    // Memoised per request: this is called by admin_locale(), permission
    // checks and sidebar rendering, all within the same request. The cache is
    // keyed by the session identity, so a session change can never serve a
    // stale user (and stale role) to a permission check.
    static $cached = null;
    static $cachedFor = null;

    $pdo = db();
    $userId = $_SESSION['user_id'];

    if (is_array($cached) && $cachedFor === (string) $userId) {
        return $cached;
    }

    $cachedFor = (string) $userId;

    if (is_numeric($userId)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => (int) $userId]);
    } else {
        // Legacy session created before identities moved to numeric ids.
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => (string) $userId]);
    }

    $cached = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    return $cached;
}

/**
 * The numeric id of the signed-in user, or null.
 */
function current_user_id(): ?int
{
    $user = current_user();

    return $user ? (int) $user['id'] : null;
}

/**
 * The signed-in user's username, or a neutral fallback for display.
 */
function current_username(): string
{
    $user = current_user();

    return $user ? (string) $user['username'] : 'User';
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
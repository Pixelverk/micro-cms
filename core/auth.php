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

    // Lets a later request notice that the password changed and end this
    // session (see session_validate_identity()).
    $_SESSION['auth_fingerprint'] = auth_password_fingerprint((string) $user['password_hash']);

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

// --------------------------------------------------
// Session identity
// --------------------------------------------------

/**
 * A fingerprint of a password hash, kept in the session.
 *
 * A derived value rather than the hash itself, so a later request can tell
 * that the password changed without password material sitting in the session
 * and without a second users column.
 */
function auth_password_fingerprint(?string $passwordHash): string
{
    return hash('sha256', 'auth|' . (string) $passwordHash);
}

/**
 * End a session that was opened with a password that has since changed.
 *
 * Runs once per request, before any capability check. Sessions created before
 * this check existed carry no fingerprint and adopt the current one, so an
 * upgrade does not sign everyone out.
 */
function session_validate_identity(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }

    $user = current_user();

    if ($user === null) {
        session_forget_identity();
        return;
    }

    $fingerprint = auth_password_fingerprint((string) $user['password_hash']);

    if (empty($_SESSION['auth_fingerprint'])) {
        $_SESSION['auth_fingerprint'] = $fingerprint;
        return;
    }

    if (!hash_equals((string) $_SESSION['auth_fingerprint'], $fingerprint)) {
        session_forget_identity();
    }
}

/**
 * Drop the signed-in identity without the activity entry a deliberate logout
 * records. The session itself stays, now anonymous.
 */
function session_forget_identity(): void
{
    $_SESSION = [];

    if (function_exists('preview_token_clear')) {
        preview_token_clear();
    }
}

// --------------------------------------------------
// Password reset
// --------------------------------------------------

/**
 * How long a reset link stays valid.
 */
function password_reset_ttl(): int
{
    return 3600;
}

/**
 * Per-address limit on reset requests.
 *
 * Counts every request, whether or not the address exists, so the limit can
 * never be used to tell the two apart. Fails open if the table is missing.
 */
function password_reset_rate_limit_ok(int $maxPerHour = 5): bool
{
    try {
        $pdo = db();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $cutoff = time() - 3600;

        $count = $pdo->prepare("
            SELECT COUNT(*) FROM form_rate_limits
            WHERE form_type = 'password_reset' AND ip = :ip AND created_at > :cutoff
        ");
        $count->execute(['ip' => $ip, 'cutoff' => $cutoff]);

        if ((int) $count->fetchColumn() >= $maxPerHour) {
            return false;
        }

        $pdo->prepare("
            INSERT INTO form_rate_limits (form_type, ip, created_at)
            VALUES ('password_reset', :ip, :now)
        ")->execute(['ip' => $ip, 'now' => time()]);

        return true;
    } catch (Throwable $exception) {
        return true;
    }
}

/**
 * Issue a reset link for an address.
 *
 * Silent about whether the address matched: the caller shows the same message
 * either way. Returns the raw token when one was created (tests use it), null
 * otherwise.
 */
function password_reset_request(string $email): ?string
{
    $email = trim($email);
    $raw = null;

    $stmt = db()->prepare("SELECT id, username, email FROM users WHERE email = :email COLLATE NOCASE LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $raw = bin2hex(random_bytes(32));
        $pdo = db();
        $now = time();

        // Only the newest link works, and expired rows never pile up.
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = :id OR expires_at < :now")
            ->execute(['id' => (int) $user['id'], 'now' => $now]);

        $pdo->prepare("
            INSERT INTO password_resets (user_id, token_hash, ip, expires_at, created_at)
            VALUES (:user_id, :token_hash, :ip, :expires_at, :created_at)
        ")->execute([
            'user_id'    => (int) $user['id'],
            'token_hash' => hash('sha256', $raw),
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'expires_at' => $now + password_reset_ttl(),
            'created_at' => $now,
        ]);

        password_reset_mail($user, $raw);
    }

    log_activity('user.password_reset_requested', 'user', $user ? (int) $user['id'] : null, $email);

    return $raw;
}

/**
 * The unexpired, unused reset row a raw token belongs to, or null.
 */
function password_reset_find(string $rawToken): ?array
{
    $rawToken = trim($rawToken);

    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT * FROM password_resets
        WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now
        LIMIT 1
    ");
    $stmt->execute(['hash' => hash('sha256', $rawToken), 'now' => time()]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Apply a new password and consume the token that authorised it.
 */
function password_reset_complete(array $reset, string $newPassword): void
{
    $pdo = db();
    $userId = (int) $reset['user_id'];

    $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
        ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);

    // Single use: a replayed link finds a consumed row.
    $pdo->prepare("UPDATE password_resets SET used_at = :now WHERE id = :id")
        ->execute(['now' => time(), 'id' => (int) $reset['id']]);

    $username = (string) $pdo->query("SELECT username FROM users WHERE id = " . $userId)->fetchColumn();

    log_activity('user.password_reset', 'user', $userId, $username);
}

/**
 * The reset email. In production it goes out with mail(); everywhere else it
 * is appended to storage/logs/forms.log the way core/form-submit.php logs.
 */
function password_reset_mail(array $user, string $rawToken): void
{
    $link = seo_absolute_url(url('admin/reset-password')) . '?token=' . urlencode($rawToken);
    $site = (string) get_setting('site_title', 'Micro CMS');

    $subject = 'Reset your password for ' . $site;
    $body = "Someone asked to reset the password for your account.\n\n"
        . "Open this link within an hour to choose a new one:\n\n"
        . $link . "\n\n"
        . "If you did not ask for this, you can ignore this message.\n";

    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    if ((config('env') ?? 'production') !== 'production') {
        file_put_contents(
            STORAGE_PATH . '/logs/forms.log',
            json_encode([
                'to'      => (string) $user['email'],
                'subject' => $subject,
                'body'    => $body,
                'headers' => $headers,
                'time'    => date('c'),
            ], JSON_PRETTY_PRINT) . "\n\n",
            FILE_APPEND
        );

        return;
    }

    if (!mail((string) $user['email'], $subject, $body, implode("\r\n", $headers))) {
        debug_log('password reset mail failed for user ' . (int) $user['id']);
    }
}
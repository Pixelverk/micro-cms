<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Login Throttling
|--------------------------------------------------------------------------
|
| Backed by the login_attempts table (created by a migration, with a
| self-healing fallback for installs that have not migrated yet).
|
| Key = sha256(username|ip), so a single account cannot be brute-forced from
| one address, and one address cannot spray many accounts.
|
*/

function throttle_table_exists(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $pdo = db();
        $pdo->query("SELECT 1 FROM login_attempts LIMIT 1");
        $exists = true;
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

function throttle_key(string $username): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';

    return hash('sha256', strtolower(trim($username)) . '|' . $ip);
}

function throttle_max_attempts(): int
{
    return max(1, (int) config('security.login_max_attempts', 5));
}

function throttle_lockout_seconds(): int
{
    return max(30, (int) config('security.login_lockout_seconds', 900));
}

/**
 * Is this username/IP pair currently locked out?
 */
function throttle_is_locked(string $username): bool
{
    if (!throttle_table_exists()) {
        return false;
    }

    $stmt = db()->prepare("SELECT locked_until FROM login_attempts WHERE key_hash = :key LIMIT 1");
    $stmt->execute(['key' => throttle_key($username)]);
    $lockedUntil = $stmt->fetchColumn();

    if ($lockedUntil === false || $lockedUntil === null) {
        return false;
    }

    return (int) $lockedUntil > time();
}

/**
 * Seconds left in the current lockout (0 when not locked).
 */
function throttle_seconds_remaining(string $username): int
{
    if (!throttle_table_exists()) {
        return 0;
    }

    $stmt = db()->prepare("SELECT locked_until FROM login_attempts WHERE key_hash = :key LIMIT 1");
    $stmt->execute(['key' => throttle_key($username)]);
    $lockedUntil = (int) $stmt->fetchColumn();

    return $lockedUntil > time() ? $lockedUntil - time() : 0;
}

/**
 * Record a failed attempt. Returns true when this failure triggered a lockout.
 */
function throttle_fail(string $username): bool
{
    if (!throttle_table_exists()) {
        return false;
    }

    $pdo = db();
    $now = time();
    $key = throttle_key($username);

    $stmt = $pdo->prepare("SELECT id, attempts FROM login_attempts WHERE key_hash = :key LIMIT 1");
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $attempts = $row ? (int) $row['attempts'] + 1 : 1;
    $lockedUntil = null;

    if ($attempts >= throttle_max_attempts()) {
        $lockedUntil = $now + throttle_lockout_seconds();
        $attempts = 0; // start a fresh window after the lockout expires
    }

    if ($row) {
        $update = $pdo->prepare("
            UPDATE login_attempts
            SET attempts = :attempts, last_attempt = :now, locked_until = :locked
            WHERE id = :id
        ");
        $update->execute([
            'attempts' => $attempts,
            'now'      => $now,
            'locked'   => $lockedUntil,
            'id'       => $row['id'],
        ]);
    } else {
        $insert = $pdo->prepare("
            INSERT INTO login_attempts (key_hash, ip, attempts, last_attempt, locked_until)
            VALUES (:key, :ip, :attempts, :now, :locked)
        ");
        $insert->execute([
            'key'      => $key,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'attempts' => $attempts,
            'now'      => $now,
            'locked'   => $lockedUntil,
        ]);
    }

    return $lockedUntil !== null;
}

/**
 * Clear the record after a successful login.
 */
function throttle_clear(string $username): void
{
    if (!throttle_table_exists()) {
        return;
    }

    $stmt = db()->prepare("DELETE FROM login_attempts WHERE key_hash = :key");
    $stmt->execute(['key' => throttle_key($username)]);
}

/**
 * Opportunistic cleanup of stale rows (called on a small percentage of logins).
 */
function throttle_prune(int $olderThanDays = 7): void
{
    if (!throttle_table_exists()) {
        return;
    }

    $cutoff = time() - ($olderThanDays * 86400);

    $stmt = db()->prepare("
        DELETE FROM login_attempts
        WHERE last_attempt < :cutoff
          AND (locked_until IS NULL OR locked_until < :now)
    ");
    $stmt->execute(['cutoff' => $cutoff, 'now' => time()]);
}

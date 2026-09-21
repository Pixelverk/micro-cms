<?php
declare(strict_types=1);

/**
 * Get a PDO connection to the SQLite database
 */
function db(): PDO
{
    $dbPath = STORAGE_PATH . '/data.sqlite';

    if (!file_exists($dbPath)) {
        if (defined('CMS_SETUP_READONLY')) {
            throw new RuntimeException("Test database missing at {$dbPath} — call test_fresh_database() first.");
        }

        // Never expose paths or setup instructions to a production visitor.
        if ((config('env') ?? 'production') === 'production') {
            debug_log('Database missing at ' . $dbPath);
            http_response_code(503);
            exit('Service temporarily unavailable.');
        }

        echo'No database file found!<br>';
        echo'Set the value of "setup_completed" in config.php to false and reload the page.<br>';
        echo'That should run the intial setup and create a DB with some default content.<br>';
        echo'If things still fail, you might not have the PDO extension activated in PHP.<br>';
        exit;
    }

    // important, static = same the entire request, avoids repeated db connection.
    static $pdo = null;
    static $connectedPath = null;

    // Reconnects when the storage path changes (test processes / fresh fixtures).
    if ($pdo instanceof PDO && $connectedPath === $dbPath) {
        return $pdo;
    }

    $connectedPath = $dbPath;

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Self-healing schema additions. A brand-new or partially initialised
    // database has none of these tables yet, so every step is optional and
    // must never fatal — the installer owns the first real schema.
    try {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];

        if (in_array('users', $tables, true)) {
            $userColumns = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);

            if ($userColumns && !in_array('ui_language', $userColumns, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN ui_language TEXT NULL");
            }

            // Sessions created before identities moved to numeric ids.
            if (!empty($_SESSION['user_id']) && !is_numeric($_SESSION['user_id'])) {
                $legacy = $_SESSION['user_id'];
                $lookup = $pdo->prepare("SELECT id, username FROM users WHERE username = :username LIMIT 1");
                $lookup->execute(['username' => (string) $legacy]);
                $legacyUser = $lookup->fetch(PDO::FETCH_ASSOC);

                if ($legacyUser) {
                    $_SESSION['user_id']  = (int) $legacyUser['id'];
                    $_SESSION['username'] = $legacyUser['username'];
                } else {
                    // Unknown identity: drop it rather than keeping a stale string.
                    unset($_SESSION['user_id'], $_SESSION['username']);
                }
            }
        }
    } catch (Throwable $exception) {
        debug_log('db() self-heal skipped: ' . $exception->getMessage());
    }

    return $pdo;
}

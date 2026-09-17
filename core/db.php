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

        // Login throttling (see core/helpers/throttle.php).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_hash TEXT NOT NULL UNIQUE,
                ip TEXT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                last_attempt INTEGER NOT NULL,
                locked_until INTEGER NULL
            )
        ");
    } catch (Throwable $exception) {
        debug_log('db() self-heal skipped: ' . $exception->getMessage());
    }

    return $pdo;
}


// load taxonomy archive data
function load_taxonomy_archive(string $taxonomyType, string $slug): array
{
    $pdo = db();

    // ----------------------------
    // Load the taxonomy term
    // ----------------------------
    $stmt = $pdo->prepare("
        SELECT *
        FROM taxonomy
        WHERE taxonomy_type = :type
          AND slug = :slug
        LIMIT 1
    ");
    $stmt->execute([
        'type' => $taxonomyType,
        'slug' => $slug,
    ]);

    $taxonomy = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$taxonomy) {
        return load_fallback_404();
    }

    $contentType = $taxonomy['content_type'];

    // ----------------------------
    // Load all content items linked to this taxonomy
    // ----------------------------
    $stmt = $pdo->prepare("
        SELECT c.*
        FROM content c
        INNER JOIN taxonomy_term_relationships ttr
            ON ttr.content_id = c.id
           AND ttr.content_type = c.type
        WHERE ttr.taxonomy_id = :taxId
        ORDER BY c.created_at DESC
    ");
    $stmt->execute(['taxId' => $taxonomy['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // decode JSON fields and attach taxonomies to each item
    foreach ($items as &$item) {
        $item['meta'] = $item['meta'] ? json_decode($item['meta'], true) : [];
        $item['body'] = $item['body'] ? json_decode($item['body'], true) : [];
        $item['categories'] = [];
        $item['tags']       = [];

        $taxes = load_taxonomies_for_content($item['type'], $item['id']);
        $item['categories'] = $taxes['category'];
        $item['tags']       = $taxes['tag'];
    }
    unset($item);

    // ----------------------------
    // Determine layout
    // ----------------------------
    $theme = theme_config();
    $contentTypes = $theme['content_types'] ?? [];
    $ctConfig = $contentTypes[$contentType] ?? [];

    $layout = $ctConfig['taxonomy_layout'] ?? 'taxonomy';

    // ----------------------------
    // Build page array
    // ----------------------------
    $page = [
        'id'         => null,
        'type'       => $contentType,
        'slug'       => $slug,
        'status'     => 'published',
        'title'      => $taxonomy['name'],
        'layout'     => $layout,
        'taxonomy'   => $taxonomy,
        'items'      => $items,
        'components' => [], // not used for archive layouts
        'updated_at' => time(),
    ];

    // Optional: header/footer defaults
    $settings = load_settings();
    $page['header'] = $ctConfig['default_header'] ?? $settings['default_header'] ?? $theme['defaults']['header'] ?? 'site-header';
    $page['footer'] = $ctConfig['default_footer'] ?? $settings['default_footer'] ?? $theme['defaults']['footer'] ?? 'site-footer';

    return $page;
}
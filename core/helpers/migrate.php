<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Schema migrations
|--------------------------------------------------------------------------
|
| There is no CLI here, so migrations run opportunistically: `index.php` calls
| migrate_run() after the installer check, and the admin utilities page offers
| a manual "run migrations" button.
|
| Two rules keep this cheap on a normal request:
|
|  1. A marker file (storage/.migrations) records the newest applied migration.
|     When it matches the registry's last key, no database work happens at all.
|  2. Migrations must be idempotent, because a crashed run may repeat one.
|
| Fresh installs do NOT run these — they are the "upgrade path" for databases
| created by an older setup.php. When you add a migration, also add the same
| change to setup.php so new installs start from the current schema.
|
*/

/**
 * Ordered registry of migrations. Keys must sort chronologically.
 *
 * @return array<string, callable(PDO): void>
 */
function migrate_registry(): array
{
    return [
        // Content added after the original release. Nullable columns with no
        // backfill, so existing rows keep working unchanged.
        '2026_09_17_000001_content_authorship' => function (PDO $pdo): void {
            migrate_add_column($pdo, 'content', 'created_by', 'INTEGER NULL');
            migrate_add_column($pdo, 'content', 'updated_by', 'INTEGER NULL');
        },

        // Search needs a plain-text copy of the body.
        '2026_09_17_000002_content_search_text' => function (PDO $pdo): void {
            migrate_add_column($pdo, 'content', 'search_text', 'TEXT NULL');
        },

        // Login throttling and form rate limiting.
        '2026_09_17_000003_rate_limit_tables' => function (PDO $pdo): void {
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

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS form_rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    form_type TEXT NOT NULL,
                    ip TEXT NOT NULL,
                    created_at INTEGER NOT NULL
                )
            ");

            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_form_rate_limits_lookup ON form_rate_limits (form_type, ip, created_at)");
        },

        // Legacy rows published without a timestamp were invisible to the
        // front end; give them one so they appear in listings and sitemaps.
        '2026_09_17_000004_backfill_published_at' => function (PDO $pdo): void {
            $pdo->exec("
                UPDATE content
                SET published_at = COALESCE(published_at, updated_at, created_at, strftime('%s', 'now'))
                WHERE status = 'published'
                  AND published_at IS NULL
            ");
        },

        // Helpful indexes for the visibility predicate and listings.
        '2026_09_17_000005_content_indexes' => function (PDO $pdo): void {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_visibility ON content (type, status, published_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_parent ON content (parent_id)");
        },

        // Snapshot history for content.
        '2026_09_17_000006_content_versions' => function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS content_versions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    content_id INTEGER NOT NULL,
                    version INTEGER NOT NULL,
                    title TEXT NOT NULL,
                    status TEXT NOT NULL,
                    layout TEXT,
                    header TEXT,
                    footer TEXT,
                    meta JSON,
                    body JSON NOT NULL,
                    published_at INTEGER,
                    scheduled_at INTEGER,
                    reason TEXT NOT NULL DEFAULT 'save',
                    content_hash TEXT NOT NULL,
                    created_by INTEGER NULL,
                    created_at INTEGER NOT NULL,
                    UNIQUE(content_id, version)
                )
            ");

            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_versions_item ON content_versions (content_id, version DESC)");
        },

        // Audit trail.
        '2026_09_17_000007_activity_log' => function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS activity_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NULL,
                    username TEXT NULL,
                    action TEXT NOT NULL,
                    object_type TEXT NULL,
                    object_id INTEGER NULL,
                    summary TEXT NULL,
                    meta JSON NULL,
                    ip TEXT NULL,
                    created_at INTEGER NOT NULL
                )
            ");

            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_created ON activity_log (created_at DESC)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_object ON activity_log (object_type, object_id)");
        },

        // Roles and permissions. Existing users become admins so nobody is
        // locked out by the upgrade.
        '2026_09_17_000008_user_roles' => function (PDO $pdo): void {
            migrate_add_column($pdo, 'users', 'role', "TEXT NOT NULL DEFAULT 'admin'");

            $pdo->exec("UPDATE users SET role = 'admin' WHERE role IS NULL OR role = ''");
        },

        // Backfill the search index for installs that predate it.
        '2026_09_17_000009_search_text_backfill' => function (PDO $pdo): void {
            migrate_add_column($pdo, 'content', 'search_text', 'TEXT NULL');
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_search ON content (search_text)");

            // Index titles immediately so search is useful before the next
            // save touches each row. Bodies fill in as content is edited.
            $pdo->exec("UPDATE content SET search_text = title WHERE search_text IS NULL OR search_text = ''");
        },

        // Traffic counting (see core/helpers/analytics.php).
        '2026_09_17_000010_page_views' => function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS page_views (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    path TEXT NOT NULL,
                    content_id INTEGER NULL,
                    referrer_host TEXT NULL,
                    ua_hash TEXT NULL,
                    visitor_hash TEXT NOT NULL,
                    is_bot INTEGER NOT NULL DEFAULT 0,
                    viewed_at INTEGER NOT NULL
                )
            ");

            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_time ON page_views (viewed_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_path ON page_views (path, viewed_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_visitor ON page_views (visitor_hash, viewed_at)");
        },

        // How each view was served, so the cache-hit ratio comes from the
        // views themselves instead of the optional perf.log.
        '2026_09_17_000011_page_views_cache_hit' => function (PDO $pdo): void {
            migrate_add_column($pdo, 'page_views', 'cache_hit', 'INTEGER NOT NULL DEFAULT 0');
        },

    ];
}

/**
 * Add a column unless it is already there.
 */
function migrate_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);

    if ($columns && in_array($column, $columns, true)) {
        return;
    }

    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

function migrate_marker_path(): string
{
    return STORAGE_PATH . '/.migrations';
}

/**
 * Run any migrations that have not been applied yet.
 *
 * @return list<string> the keys that ran
 */
function migrate_run(): array
{
    $registry = migrate_registry();

    if (!$registry) {
        return [];
    }

    $keys       = array_keys($registry);
    $newest     = end($keys);
    $markerPath = migrate_marker_path();

    // Fast path: nothing to do, no database access at all.
    if (is_file($markerPath) && trim((string) @file_get_contents($markerPath)) === $newest) {
        return [];
    }

    try {
        $pdo = db();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id TEXT PRIMARY KEY,
                applied_at INTEGER NOT NULL
            )
        ");

        $applied = $pdo->query("SELECT id FROM migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $applied = array_flip($applied);

        $ran = [];

        foreach ($registry as $key => $migration) {
            if (isset($applied[$key])) {
                continue;
            }

            $migration($pdo);

            $stmt = $pdo->prepare("INSERT OR REPLACE INTO migrations (id, applied_at) VALUES (:id, :now)");
            $stmt->execute(['id' => $key, 'now' => time()]);

            $ran[] = $key;
        }

        // Only record success once every migration has been attempted.
        @file_put_contents($markerPath, $newest, LOCK_EX);

        return $ran;
    } catch (Throwable $exception) {
        // A broken migration must not take the whole site down; log it and
        // leave the marker untouched so the next request retries.
        debug_log('migrate_run failed: ' . $exception->getMessage());

        return [];
    }
}

/**
 * Forget the marker so the next request re-checks the registry.
 */
function migrate_reset_marker(): void
{
    @unlink(migrate_marker_path());
}

/**
 * Applied migration keys (for the admin utilities page).
 *
 * @return list<string>
 */
function migrate_applied(): array
{
    try {
        $pdo = db();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id TEXT PRIMARY KEY,
                applied_at INTEGER NOT NULL
            )
        ");

        return $pdo->query("SELECT id FROM migrations ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $exception) {
        return [];
    }
}

/*
|--------------------------------------------------------------------------
| Front-end upgrade gate
|--------------------------------------------------------------------------
| A database created by an older release is missing columns that the current
| read paths select. Running the migration registry can only work if the
| database is writable, so check that first and explain the problem instead of
| letting a PDO error surface as a blank 500.
*/

/**
 * Can this database be modified at all?
 */
function database_is_writable(): bool
{
    $path = STORAGE_PATH . '/data.sqlite';

    if (!is_file($path)) {
        return false;
    }

    return is_writable($path) && is_writable(dirname($path));
}

/**
 * Upgrade the schema before any read, or explain why that is impossible.
 */
function migrate_before_read(): void
{
    // Test and CLI contexts manage their own storage.
    if (defined('CMS_SETUP_READONLY')) {
        return;
    }

    if (!database_is_writable()) {
        render_database_problem(
            'The database is not writable',
            [
                'The CMS needs to write to storage/data.sqlite to apply schema updates.',
                'The file or its directory is read-only for the user running PHP (often www-data or nobody).',
                'Fix it with: sudo chown -R www-data:www-data storage',
                'Then reload this page; the upgrade runs automatically.',
            ]
        );
    }

    try {
        migrate_run();
    } catch (Throwable $exception) {
        debug_log('migrate_before_read failed: ' . $exception->getMessage());

        render_database_problem(
            'The database could not be upgraded',
            [
                $exception->getMessage(),
                'Restore a backup if this install holds live content, then run the migrations from Admin → Utilities.',
            ]
        );
    }
}

/**
 * A plain, readable failure page. Deliberately dependency-free: at this point
 * the theme and layout may not be loadable.
 */
function render_database_problem(string $title, array $lines): void
{
    http_response_code(503);


    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<title>' . e($title) . '</title>';
    echo '<style>body{font:16px/1.6 system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1.5rem;color:#1f2937}';
    echo 'h1{font-size:1.3rem}li{margin-bottom:.4rem}code{background:#f3f4f6;padding:.1rem .3rem;border-radius:3px}</style>';
    echo '</head><body><h1>' . e($title) . '</h1><ul>';

    foreach ($lines as $line) {
        echo '<li>' . e($line) . '</li>';
    }

    echo '</ul></body></html>';
    exit;
}

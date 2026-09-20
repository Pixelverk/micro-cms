<?php
declare(strict_types=1);

// Ensure Storage Directories Exist
foreach (['', '/cache', '/media', '/logs', '/imports'] as $dir) {
    $full = STORAGE_PATH . $dir;
    if (!is_dir($full)) mkdir($full, 0775, true);
}

$dbPath = STORAGE_PATH . '/data.sqlite';

if (file_exists($dbPath)) {
    echo 'First setup tried to run, but there is already a file at /storage/data.sqlite <br>';
    echo 'Either remove the file or set the value of "setup_completed" in config.php to "true" <br>';
    exit;
}

/*
|--------------------------------------------------------------------------
| Create SQLite Database
|--------------------------------------------------------------------------
*/
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Create Tables
|--------------------------------------------------------------------------
*/

$pdo->exec("
CREATE TABLE content (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    slug TEXT NOT NULL,
    parent_id INTEGER NULL,
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    layout TEXT,
    header TEXT,
    footer TEXT,
    meta JSON,
    body JSON NOT NULL,
    published_at INTEGER,
    scheduled_at INTEGER,
    created_by INTEGER NULL,
    updated_by INTEGER NULL,
    search_text TEXT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    deleted_at INTEGER NULL,
    UNIQUE(type, parent_id, slug)
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_visibility ON content (type, status, published_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_parent ON content (parent_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_search ON content (search_text)");

$pdo->exec("
CREATE TABLE content_versions (
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
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_versions_item ON content_versions (content_id, version DESC)");

$pdo->exec("
CREATE TABLE activity_log (
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
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_created ON activity_log (created_at DESC)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_object ON activity_log (object_type, object_id)");

// Traffic counting. No IP addresses are stored; the only visitor-level value
// is a per-day hash (see core/helpers/analytics.php).
$pdo->exec("
CREATE TABLE page_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    path TEXT NOT NULL,
    content_id INTEGER NULL,
    referrer_host TEXT NULL,
    ua_hash TEXT NULL,
    visitor_hash TEXT NOT NULL,
    is_bot INTEGER NOT NULL DEFAULT 0,
    cache_hit INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 200,
    viewed_at INTEGER NOT NULL
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_time ON page_views (viewed_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_path ON page_views (path, viewed_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_visitor ON page_views (visitor_hash, viewed_at)");

// Old URLs that must keep working (see core/helpers/redirects.php).
$pdo->exec("
CREATE TABLE redirects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    from_path TEXT NOT NULL UNIQUE,
    to_path TEXT NOT NULL,
    status INTEGER NOT NULL DEFAULT 301,
    hits INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);
");

$pdo->exec("
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    first_name TEXT,
    last_name TEXT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    ui_language TEXT NULL,
    created_at INTEGER NOT NULL,
    last_login INTEGER
);
");

$pdo->exec("
CREATE TABLE settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    `key` TEXT NOT NULL UNIQUE,
    `value` TEXT NOT NULL,
    updated_at INTEGER NOT NULL
);
");

$pdo->exec("
CREATE TABLE menus (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    label TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    items JSON NOT NULL,
    updated_at INTEGER NOT NULL
);
");

$pdo->exec("
CREATE TABLE form_submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_type TEXT NOT NULL,
    page_id INTEGER NULL,
    data TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'new',
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
");

$pdo->exec("
CREATE TABLE media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    original_name TEXT NOT NULL,
    base_path TEXT NOT NULL,

    mime_type TEXT NOT NULL,
    original_size INTEGER NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,

    sizes_json TEXT NOT NULL,
    formats_json TEXT NOT NULL,
    lqip_base64 TEXT,

    alt_text TEXT,
    description TEXT,

    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
");

$pdo->exec("
CREATE TABLE taxonomy (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    taxonomy_type TEXT NOT NULL,
    content_type TEXT NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    description TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    UNIQUE(taxonomy_type, slug)
);
");

$pdo->exec("
CREATE TABLE taxonomy_term_relationships (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_type TEXT NOT NULL,
    content_id INTEGER NOT NULL,
    taxonomy_id INTEGER NOT NULL,
    UNIQUE(content_type, content_id, taxonomy_id)
);
");

// Rate limiting: login lockouts and public-form throttling.
$pdo->exec("
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key_hash TEXT NOT NULL UNIQUE,
    ip TEXT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_attempt INTEGER NOT NULL,
    locked_until INTEGER NULL
);
");

$pdo->exec("
CREATE TABLE form_rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_type TEXT NOT NULL,
    ip TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_form_rate_limits_lookup ON form_rate_limits (form_type, ip, created_at)");

// One-time password reset tokens: only the hash is stored, the row expires,
// and completing a reset consumes it.
$pdo->exec("
CREATE TABLE password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    ip TEXT NULL,
    expires_at INTEGER NOT NULL,
    used_at INTEGER NULL,
    created_at INTEGER NOT NULL
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets (user_id)");

// what time is it?
$now = time();

/*
|--------------------------------------------------------------------------
| Insert the demo users
|--------------------------------------------------------------------------
| One account per role, so a fresh install can be explored from every point of
| view — what an author may do is only obvious next to what an editor may do.
| Each password is its username; these are demo logins, and README.md lists
| them. A real install changes the passwords on the Users page.
*/

$demoUsers = [
    ['username' => 'admin',   'first_name' => 'Albert', 'last_name' => 'Administrator', 'email' => 'admin@example.com',  'role' => 'admin'],
    ['username' => 'editor', 'first_name' => 'Edith',  'last_name' => 'Editor',        'email' => 'editor@example.com', 'role' => 'editor'],
    ['username' => 'author', 'first_name' => 'Anna',   'last_name' => 'Author',        'email' => 'author@example.com', 'role' => 'author'],
];

$stmt = $pdo->prepare("
INSERT INTO users (username, first_name, last_name, email, password_hash, role, created_at, last_login)
VALUES (:username, :first_name, :last_name, :email, :password_hash, :role, :created_at, :last_login)
");

foreach ($demoUsers as $user) {
    $stmt->execute([
        'username'      => $user['username'],
        'first_name'    => $user['first_name'],
        'last_name'     => $user['last_name'],
        'email'         => $user['email'],
        'password_hash' => password_hash($user['username'], PASSWORD_DEFAULT),
        'role'          => $user['role'],
        'created_at'    => $now,
        'last_login'    => $now,
    ]);
}

/*
|--------------------------------------------------------------------------
| Insert Default Settings
|--------------------------------------------------------------------------
*/

// These are the app's own defaults. The theme's demo settings, imported below,
// override the ones that describe content (site title, defaults, prefixes).
$settings = [
    'site_title'      => 'Awesome site',
    'site_language'   => 'en',
    'timezone'        => 'Europe/Stockholm',
    'date_format'     => 'F j, Y',
    'admin_default_language' => 'en',
    'default_layout'  => 'default',
    'default_header'  => 'site-header',
    'default_footer'  => 'site-footer',
    'content_prefixes'=> [
        'page'           => '',
        'blog_post'      => 'blog',
        'portfolio_item' => 'portfolio'
    ],
    'menu_locations' => [
        'main' => 'main',
        'footer' => 'footer',
    ],
    'contact_email'   => 'test-admin@domain.com',
    'media_sizes' => [400, 800, 1200, 2000],
    'generate_webp' => true,
    'quality_webp' => 80,
    'strip_metadata' => true,
];

$stmt = $pdo->prepare("
    INSERT INTO settings (`key`, `value`, updated_at)
    VALUES (:key, :value, :updated_at)
    ON CONFLICT(`key`) DO NOTHING
");

foreach ($settings as $key => $value) {
    $stmt->execute([
        'key'        => $key,
        'value'      => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value,
        'updated_at' => $now,
    ]);
}

/*
|--------------------------------------------------------------------------
| Import the theme demo content
|--------------------------------------------------------------------------
| The demo belongs to the theme: theme/demo/content.json and
| theme/demo/settings.json. Keeping it there means a theme developer works on
| their content beside their components, and that the installer has no opinion
| about what a site contains.
|
| A theme with no demo folder installs empty. The same importer is available
| from Utilities, so content can be loaded or replaced later.
*/

require_once CORE_PATH . '/helpers/common.php';
bootstrap_core();

$demo = content_package_theme_demo();

if ($demo['documents']) {
    $merged = content_package_merge($demo['documents']);
    $plan   = content_package_plan($merged['package']);

    $problems = array_merge($demo['errors'], $merged['problems'], $plan['problems']);

    if ($problems) {
        // These files ship with the theme, so a problem is a theme bug rather
        // than bad input: leave the site empty and record why.
        debug_log('theme demo import refused: ' . implode('; ', $problems));
    } else {
        // No sitemap: a fresh install has no configured origin yet, so it would
        // fill up with localhost URLs. The first save or Utilities run writes it.
        content_package_import($merged['package'], ['sitemap' => false]);
    }
}

/* update the config to say setup has been done */
function update_config_value(string $key, mixed $value): bool
{
    // Test runs must never rewrite the tracked config file.
    if (defined('CMS_SETUP_READONLY') && CMS_SETUP_READONLY) {
        return false;
    }

    $configFile = CMS_PATH . '/config.php';

    if (!is_writable($configFile)) {
        return false;
    }

    $contents = file_get_contents($configFile);
    if ($contents === false) {
        return false;
    }

    // Convert value to PHP literal
    $exported = var_export($value, true);

    // Replace only the specific key (top-level)
    $pattern = "/(['\"]" . preg_quote($key, '/') . "['\"]\s*=>\s*)([^,]+),/";
    $replacement = "$1{$exported},";

    $updated = preg_replace($pattern, $replacement, $contents, 1, $count);

    if ($count !== 1) {
        return false; // key not found or ambiguous
    }

    return file_put_contents($configFile, $updated, LOCK_EX) !== false;
}

update_config_value('setup_completed', true);

/*
|--------------------------------------------------------------------------
| First Run Complete
|--------------------------------------------------------------------------
*/

// Test runs seed the database and continue; a real first visit gets a message.
if (defined('CMS_SETUP_READONLY') && CMS_SETUP_READONLY) {
    return;
}

header('Refresh: 3');
echo('Initial setup completed, the page will now refresh.');
exit;
<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Duplicating content (end-to-end)
|--------------------------------------------------------------------------
|
| A copy is a new draft. The interesting parts are not the insert but the
| edges: a unique slug and title, a slug that a trashed item still occupies,
| taxonomies coming across, the canonical being dropped, and the capability
| gate. Those only exist through the real request path, so this suite runs a
| server the same way http.test.php does.
|
*/

if (getenv('CMS_TEST_DUPLICATE') !== '1') {
    $child = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);

    $output = [];
    $exitCode = 0;
    exec('CMS_TEST_DUPLICATE=1 ' . $child . ' 2>&1', $output, $exitCode);

    echo implode("\n", $output), "\n";
    exit($exitCode);
}

require __DIR__ . '/bootstrap.php';

if (!extension_loaded('curl')) {
    echo "FAIL duplicate suite — the curl extension is required\n";
    exit(1);
}

$port      = 8600 + random_int(0, 300);
$base      = "http://127.0.0.1:{$port}";
$cookieJar = test_tmp_root() . '/cookies.txt';

test_fresh_database();

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', test_tmp_root() . '/duplicate-server.log', 'a'], 2 => ['file', test_tmp_root() . '/duplicate-server.log', 'a']],
    $pipes,
    CMS_PATH,
    ['CMS_CONFIG_FILE' => CMS_PATH . '/tests/config.server.php'] + $_ENV
);

if (!is_resource($server)) {
    echo "FAIL duplicate suite — could not start the test server\n";
    exit(1);
}

/**
 * Perform a request and return [status, body, headers].
 */
function dup_http(string $method, string $url, bool $useCookies = true, array $post = []): array
{
    global $cookieJar;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HEADER         => true,
    ]);

    if ($useCookies) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    if ($post) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("request failed: {$error}");
    }

    return [$status, substr($response, $headerSize), substr($response, 0, $headerSize)];
}

function dup_login(string $base, string $username, string $password): void
{
    global $cookieJar;

    file_put_contents($cookieJar, '');

    [, $loginPage] = dup_http('GET', $base . '/admin/login');

    if (!preg_match('/name="_token" value="([^"]+)"/', $loginPage, $matches)) {
        throw new RuntimeException('login page did not contain a CSRF token');
    }

    [$status] = dup_http('POST', $base . '/admin/login', true, [
        'username' => $username,
        'password' => $password,
        '_token'   => $matches[1],
    ]);

    if ($status !== 302) {
        throw new RuntimeException("login as {$username} failed with status {$status}");
    }
}

function dup_csrf_token(string $base): string
{
    [, $page] = dup_http('GET', $base . '/admin/content?type=page');

    if (!preg_match('/name="_token" value="([^"]+)"/', $page, $matches)) {
        throw new RuntimeException('no CSRF token on the content list');
    }

    return $matches[1];
}

/**
 * The most recent row for a slug, whatever its state.
 */
function dup_row_by_slug(string $slug, string $type = 'page'): ?array
{
    $stmt = db()->prepare("SELECT * FROM content WHERE type = :type AND slug = :slug ORDER BY id DESC LIMIT 1");
    $stmt->execute(['type' => $type, 'slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function dup_taxonomy_ids(int $id, string $type = 'page'): array
{
    $stmt = db()->prepare("
        SELECT taxonomy_id FROM taxonomy_term_relationships
        WHERE content_type = :type AND content_id = :id
        ORDER BY taxonomy_id
    ");
    $stmt->execute(['type' => $type, 'id' => $id]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Wait for the server to accept connections.
$ready = false;
for ($i = 0; $i < 60; $i++) {
    $ch = curl_init($base . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status > 0) {
        $ready = true;
        break;
    }

    usleep(100000);
}

if (!$ready) {
    proc_terminate($server);
    echo "FAIL duplicate suite — server never became ready\n";
    exit(1);
}

/** Create a page with components, meta and one category + one tag. */
function dup_seed_source(string $slug, string $title, string $status = 'published', ?int $parentId = null): int
{
    $now = time();
    $id  = seed_content([
        'type'         => 'page',
        'slug'         => $slug,
        'title'        => $title,
        'status'       => $status,
        'parent_id'    => $parentId,
        'published_at' => $status === 'published' ? $now : null,
        'layout'       => 'default',
        'header'       => 'site-header',
        'footer'       => 'site-footer',
        'meta'         => ['description' => 'Source description', 'canonical' => 'https://example.com/elsewhere'],
        'body'         => [['type' => 'hero-section', 'props' => ['title' => 'Hero title'], 'children' => []]],
    ]);

    db()->prepare("INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, created_at, updated_at)
                   VALUES ('category', 'page', :name, :slug, :now, :now)")
        ->execute(['name' => $slug . ' Cat', 'slug' => $slug . '-cat', 'now' => $now]);
    $categoryId = (int) db()->lastInsertId();

    db()->prepare("INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, created_at, updated_at)
                   VALUES ('tag', 'page', :name, :slug, :now, :now)")
        ->execute(['name' => $slug . ' Tag', 'slug' => $slug . '-tag', 'now' => $now]);
    $tagId = (int) db()->lastInsertId();

    $link = db()->prepare("INSERT INTO taxonomy_term_relationships (content_type, content_id, taxonomy_id) VALUES ('page', :id, :tax)");
    $link->execute(['id' => $id, 'tax' => $categoryId]);
    $link->execute(['id' => $id, 'tax' => $tagId]);

    return $id;
}

t('duplicating creates a draft copy and redirects to its editor', function () use ($base) {
    dup_login($base, 'admin', 'admin');

    $sourceId = dup_seed_source('dup-source', 'Dup Source');

    [$status, , $headers] = dup_http('POST', $base . '/admin/content/duplicate', true, [
        '_token' => dup_csrf_token($base),
        'id'     => $sourceId,
        'type'   => 'page',
    ]);

    assert_eq(302, $status, 'the endpoint redirects');
    assert_contains('/admin/content/edit', $headers, 'and lands on the editor');

    $source = load_content_by_id_admin($sourceId);
    $copy   = dup_row_by_slug('dup-source-copy');

    assert_true($copy !== null, 'the copy exists');

    $copyId = (int) $copy['id'];

    assert_eq('Dup Source (Copy)', $copy['title'], 'the title is marked as a copy');
    assert_eq('draft', $copy['status'], 'a copy is always a draft');
    assert_eq(null, $copy['published_at'], 'a draft carries no publish date');
    assert_eq(null, $copy['scheduled_at'], 'a draft carries no schedule');
    assert_eq($source['parent_id'], $copy['parent_id'], 'the parent is preserved');
    assert_eq($source['layout'], $copy['layout'], 'the layout is copied');
    assert_eq($source['header'], $copy['header'], 'the header is copied');
    assert_eq($source['footer'], $copy['footer'], 'the footer is copied');
    assert_eq($source['body'], json_decode($copy['body'], true), 'the components are copied');
    assert_eq('Source description', json_decode($copy['meta'], true)['description'] ?? null, 'meta is copied');
    assert_false(array_key_exists('canonical', json_decode($copy['meta'], true)), 'the canonical is dropped');
    assert_true(!$source['deleted_at'] && !$copy['deleted_at'], 'the original is untouched');
});

t('duplicating copies taxonomy relationships', function () use ($base) {
    dup_login($base, 'admin', 'admin');

    $sourceId = dup_seed_source('dup-tax-source', 'Dup Tax Source');

    dup_http('POST', $base . '/admin/content/duplicate', true, [
        '_token' => dup_csrf_token($base),
        'id'     => $sourceId,
        'type'   => 'page',
    ]);

    $copy = dup_row_by_slug('dup-tax-source-copy');
    assert_true($copy !== null, 'the copy exists');

    assert_eq(dup_taxonomy_ids($sourceId), dup_taxonomy_ids((int) $copy['id']), 'categories and tags come across');
});

t('a taken slug gets the next available suffix', function () use ($base) {
    dup_login($base, 'admin', 'admin');

    $sourceId = dup_seed_source('dup-taken', 'Dup Taken');
    seed_content(['type' => 'page', 'slug' => 'dup-taken-copy', 'title' => 'Occupied', 'status' => 'draft']);

    dup_http('POST', $base . '/admin/content/duplicate', true, [
        '_token' => dup_csrf_token($base),
        'id'     => $sourceId,
        'type'   => 'page',
    ]);

    assert_true(dup_row_by_slug('dup-taken-copy-2') !== null, 'the copy takes -copy-2 when -copy is taken');
});

t('a slug held by a trashed item is not reused', function () use ($base) {
    dup_login($base, 'admin', 'admin');

    $sourceId = dup_seed_source('dup-trashed', 'Dup Trashed');

    // A trashed sibling still occupies (type, parent_id, slug), so the copy
    // must skip it rather than fail on the unique index.
    $trashedId = seed_content(['type' => 'page', 'slug' => 'dup-trashed-copy', 'title' => 'Trashed copy', 'status' => 'draft']);
    trash_content($trashedId);

    [$status] = dup_http('POST', $base . '/admin/content/duplicate', true, [
        '_token' => dup_csrf_token($base),
        'id'     => $sourceId,
        'type'   => 'page',
    ]);

    assert_eq(302, $status);
    assert_true(dup_row_by_slug('dup-trashed-copy-2') !== null, 'the copy skips the trashed slug');
    assert_eq('dup-trashed-copy', db()->query("SELECT slug FROM content WHERE id = " . (int) $trashedId)->fetchColumn(), 'the trashed row is untouched');
});

t('duplicating twice produces distinct titles', function () use ($base) {
    dup_login($base, 'admin', 'admin');

    $sourceId = dup_seed_source('dup-twice', 'Dup Twice');

    foreach ([1, 2] as $ignored) {
        dup_http('POST', $base . '/admin/content/duplicate', true, [
            '_token' => dup_csrf_token($base),
            'id'     => $sourceId,
            'type'   => 'page',
        ]);
    }

    $first  = dup_row_by_slug('dup-twice-copy');
    $second = dup_row_by_slug('dup-twice-copy-2');

    assert_true($first !== null && $second !== null, 'both copies exist');
    assert_eq('Dup Twice (Copy)', $first['title']);
    assert_eq('Dup Twice (Copy 2)', $second['title']);
});

t('duplicating is refused without a valid token', function () use ($base) {
    dup_login($base, 'admin', 'admin');

    $sourceId = dup_seed_source('dup-no-token', 'Dup No Token');

    [$status] = dup_http('POST', $base . '/admin/content/duplicate', true, [
        'id'   => $sourceId,
        'type' => 'page',
    ]);

    assert_eq(302, $status, 'the CSRF gate bounces it');
    assert_true(dup_row_by_slug('dup-no-token-copy') === null, 'nothing was created');
});

t('an author may duplicate their own content but not someone else\'s', function () use ($base) {
    // Authors hold content.create but only edit their own rows, so the
    // ownership check is what keeps them out of another author's drafts.
    db()->prepare("
        INSERT OR IGNORE INTO users (username, email, password_hash, role, created_at)
        VALUES ('dup-author', 'dup-author@example.com', :hash, 'author', :now)
    ")->execute(['hash' => password_hash('dup-author-pass', PASSWORD_DEFAULT), 'now' => time()]);

    $authorId = (int) db()->query("SELECT id FROM users WHERE username = 'dup-author'")->fetchColumn();

    $ownId     = dup_seed_source('dup-author-own', 'Dup Author Own');
    $foreignId = seed_content([
        'type'   => 'page',
        'slug'   => 'dup-author-foreign',
        'title'  => 'Dup Author Foreign',
        'status' => 'draft',
    ]);

    // Owned by the seeded administrator, not the author under test.
    db()->prepare("UPDATE content SET created_by = 1 WHERE id = :id")->execute(['id' => $foreignId]);
    // Give the author's own item an owner too, so the check is symmetrical.
    db()->prepare("UPDATE content SET created_by = :author WHERE id = :id")->execute(['author' => $authorId, 'id' => $ownId]);

    dup_login($base, 'dup-author', 'dup-author-pass');

    // Their own item: allowed.
    [$ownStatus] = dup_http('POST', $base . '/admin/content/duplicate', true, [
        '_token' => dup_csrf_token($base),
        'id'     => $ownId,
        'type'   => 'page',
    ]);

    assert_eq(302, $ownStatus, 'an author may duplicate what they can edit');
    assert_true(dup_row_by_slug('dup-author-own-copy') !== null, 'their own copy is created');

    // Someone else's item: refused.
    [$foreignStatus] = dup_http('POST', $base . '/admin/content/duplicate', true, [
        '_token' => dup_csrf_token($base),
        'id'     => $foreignId,
        'type'   => 'page',
    ]);

    assert_eq(403, $foreignStatus, 'and gets a 403 for content they do not own');
    assert_true(dup_row_by_slug('dup-author-foreign-copy') === null, 'no copy of foreign content');

    assert_true($authorId > 0, 'the author account exists');
});

t('duplicating is audited and cannot be done over GET', function () use ($base) {
    dup_login($base, 'admin', 'admin');

    db()->exec("DELETE FROM activity_log");

    $sourceId = dup_seed_source('dup-audited', 'Dup Audited');

    [$status] = dup_http('GET', $base . '/admin/content/duplicate?id=' . $sourceId . '&type=page');
    assert_eq(405, $status, 'GET is rejected');
    assert_true(dup_row_by_slug('dup-audited-copy') === null, 'GET creates nothing');

    dup_http('POST', $base . '/admin/content/duplicate', true, [
        '_token' => dup_csrf_token($base),
        'id'     => $sourceId,
        'type'   => 'page',
    ]);

    $stmt = db()->query("SELECT COUNT(*) FROM activity_log WHERE action = 'content.duplicated'");
    assert_eq(1, (int) $stmt->fetchColumn(), 'the duplicate is logged once');
});

t('a failing write redirects with an error instead of a 500', function () use ($base) {
    dup_login($base, 'admin', 'admin');

    $sourceId = dup_seed_source('dup-db-failure', 'Dup Db Failure');

    // Break a step that runs after save_content(): the taxonomy copy. The
    // point is that no database error reaches the browser as a blank 500.
    $schema = (string) db()->query(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'taxonomy_term_relationships'"
    )->fetchColumn();
    assert_true($schema !== '', 'the relationships table exists to begin with');

    // Read the token while the list page can still render.
    $token = dup_csrf_token($base);

    db()->exec("DROP TABLE taxonomy_term_relationships");

    try {
        [$status] = dup_http('POST', $base . '/admin/content/duplicate', true, [
            '_token' => $token,
            'id'     => $sourceId,
            'type'   => 'page',
        ]);

        assert_eq(302, $status, 'the failure is reported as a redirect, not a 500');
    } finally {
        db()->exec($schema);
    }
});

proc_terminate($server);
proc_close($server);

exit(test_summary());

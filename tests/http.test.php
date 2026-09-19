<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HTTP behaviour (end-to-end through index.php)
|--------------------------------------------------------------------------
|
| Runs the real request path against PHP's built-in server so routing,
| sessions, CSRF enforcement and the cache are exercised together.
|
| To avoid an endless chain of servers, this file re-executes itself in a
| child process with CMS_TEST_HTTP=1 and only that child starts a server.
|
*/

if (getenv('CMS_TEST_HTTP') !== '1') {
    $child = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);

    $output = [];
    $exitCode = 0;
    exec('CMS_TEST_HTTP=1 ' . $child . ' 2>&1', $output, $exitCode);

    echo implode("\n", $output), "\n";
    exit($exitCode);
}

require __DIR__ . '/bootstrap.php';

if (!extension_loaded('curl')) {
    echo "FAIL HTTP suite — the curl extension is required\n";
    exit(1);
}

$port = 8123 + random_int(0, 400);
$base = "http://127.0.0.1:{$port}";
$cookieJar = test_tmp_root() . '/cookies.txt';

// Seed before the server opens the database.
test_fresh_database();

// The seeded 'demo' account is an administrator (users.role defaults to
// 'admin'), and test_fresh_database() just restored the pristine seed.

// Start from a cold cache: earlier suites may have left cached pages behind.
test_clear_cache_files();

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', test_tmp_root() . '/http-server.log', 'a'], 2 => ['file', test_tmp_root() . '/http-server.log', 'a']],
    $pipes,
    CMS_PATH,
    ['CMS_CONFIG_FILE' => CMS_PATH . '/tests/config.server.php'] + $_ENV
);

if (!is_resource($server)) {
    echo "FAIL HTTP suite — could not start the test server\n";
    exit(1);
}

/**
 * Wait for the server to accept connections.
 */
function http_ready(string $base): bool
{
    for ($i = 0; $i < 60; $i++) {
        $ch = curl_init($base . '/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status > 0) {
            return true;
        }

        usleep(100000);
    }

    return false;
}

/**
 * Perform a request and return [status, body, headers].
 */
function http(string $method, string $url, bool $useCookies = true, array $post = []): array
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

function http_login(string $base): void
{
    global $cookieJar;

    file_put_contents($cookieJar, '');

    [, $loginPage] = http('GET', $base . '/admin/login');

    if (!preg_match('/name="_token" value="([^"]+)"/', $loginPage, $matches)) {
        throw new RuntimeException('login page did not contain a CSRF token');
    }

    [$status] = http('POST', $base . '/admin/login', true, [
        'username' => 'demo',
        'password' => 'demo',
        '_token'   => $matches[1],
    ]);

    if ($status !== 302) {
        throw new RuntimeException("login failed with status {$status}");
    }
}

/**
 * The preview token issued at login, read from the cookie jar.
 *
 * The application exposes it as a cookie precisely so it is inspectable
 * outside the session file.
 */
function http_preview_token(): string
{
    global $cookieJar;

    $jar = (string) file_get_contents($cookieJar);

    if (!preg_match('/cms_preview\s+([a-f0-9]{32})/', $jar, $matches)) {
        throw new RuntimeException('no preview cookie in the jar');
    }

    return $matches[1];
}

function http_content_status(int $id): string
{
    $stmt = db()->prepare("SELECT status FROM content WHERE id = :id");
    $stmt->execute(['id' => $id]);

    return (string) $stmt->fetchColumn();
}

/**
 * Create a content row with a given status, for HTTP routing tests.
 */
function http_seed_content(string $slug, string $status, ?int $publishedAt = null): int
{
    return seed_content([
        'slug'         => $slug,
        'title'        => ucfirst(str_replace('-', ' ', $slug)),
        'status'       => $status,
        'published_at' => $publishedAt,
    ]);
}

if (!http_ready($base)) {
    proc_terminate($server);
    echo "FAIL HTTP suite — server never became ready\n";
    exit(1);
}

t('a public page renders', function () use ($base) {
    test_clear_cache_files();

    [$status, $body] = http('GET', $base . '/');

    assert_eq(200, $status);
    assert_contains('<html', $body);
});

t('an anonymous cached page is served without starting a session', function () use ($base) {
    test_clear_cache_files();

    // Warm the cache as a visitor, then request it again.
    http('GET', $base . '/about/', false);
    [$status, , $headers] = http('GET', $base . '/about/', false);

    assert_eq(200, $status);
    assert_contains('X-Cache: HIT', $headers, 'the second request is served from the cache');
    assert_not_contains('Set-Cookie', $headers, 'anonymous visitors stay sessionless');
});

t('a redirect answers with 301 and its target', function () use ($base) {
    test_clear_cache_files();

    redirect_save('old-location', 'about', 301);

    [$status, , $headers] = http('GET', $base . '/old-location/', false);

    assert_eq(301, $status, 'a permanent redirect is sent');
    assert_contains('Location: /about/', $headers, 'it points at the new path');

    $hits = (int) db()->query("SELECT hits FROM redirects WHERE from_path = 'old-location'")->fetchColumn();
    assert_eq(1, $hits, 'the hit is counted');

    $id = (int) db()->query("SELECT id FROM redirects WHERE from_path = 'old-location'")->fetchColumn();
    redirect_delete($id);
});

t('robots.txt is generated with an absolute sitemap line and custom rules', function () use ($base) {
    save_settings(['robots_extra' => "Disallow: /private/\r\nDisallow: /drafts/"]);

    [$status, $body, $headers] = http('GET', $base . '/robots.txt', false);

    assert_eq(200, $status);
    assert_contains('Content-Type: text/plain', $headers, 'served as plain text');
    assert_contains('User-agent: *', $body);
    assert_contains('Allow: /', $body);
    assert_contains('Sitemap: ' . $base . '/sitemap.xml', $body, 'the sitemap line is absolute');
    assert_contains("Disallow: /private/\nDisallow: /drafts/", $body, 'extra lines are appended, CRLF normalised');
    assert_not_contains('<html', $body, 'no page shell leaks in');
    assert_false(is_file(cache_file_for('/robots.txt')), 'robots.txt is never written to the HTML cache');

    // Clearing the setting takes the extra lines away again.
    save_settings(['robots_extra' => '']);

    [, $plain] = http('GET', $base . '/robots.txt', false);
    assert_not_contains('Disallow', $plain, 'the default body is unchanged');
});

t('a redirect cannot capture the robots.txt route', function () use ($base) {
    // The admin refuses reserved paths, and the route answers regardless.
    assert_true(redirect_is_reserved('robots.txt'), 'robots.txt is reserved');

    [$status, $body] = http('GET', $base . '/robots.txt', false);
    assert_eq(200, $status);
    assert_contains('User-agent: *', $body);
});

t('the analytics refresh ingests buffered views', function () use ($base) {
    db()->exec("DELETE FROM page_views");
    @unlink(analytics_buffer_path());
    @unlink(STORAGE_PATH . '/.analytics-ingest');

    // An anonymous visit buffers a view instead of writing it immediately.
    http('GET', $base . '/about/', false);
    assert_true(is_file(analytics_buffer_path()), 'a view is buffered');

    http_login($base);
    $token = http_csrf_token($base, '/admin/analytics');

    [$status] = http('POST', $base . '/admin/analytics', true, ['_token' => $token]);

    assert_eq(302, $status, 'the refresh redirects back with a toast');
    assert_true((int) db()->query("SELECT COUNT(*) FROM page_views")->fetchColumn() > 0, 'the buffer reached the database');

    db()->exec("DELETE FROM page_views");
    @unlink(analytics_buffer_path());
    @unlink(STORAGE_PATH . '/.analytics-ingest');
});

t('unknown admin pages require login', function () use ($base) {
    [$status] = http('GET', $base . '/admin/dashboard', false);
    assert_eq(302, $status, 'anonymous admin access must redirect');
});

t('admin POST without a token is refused', function () use ($base, $cookieJar) {
    file_put_contents($cookieJar, '');
    [$status, , $headers] = http('POST', $base . '/admin/dashboard', true, ['x' => 1]);

    assert_eq(302, $status, 'must not process the request');
    assert_contains('Location: ' . '/admin/dashboard', $headers, 'must bounce back with an error toast');
});

t('admin POST with a wrong token is refused', function () use ($base, $cookieJar) {
    file_put_contents($cookieJar, '');
    http('GET', $base . '/admin/login');
    [$status] = http('POST', $base . '/admin/dashboard', true, ['_token' => 'nope']);

    assert_eq(302, $status);
});

t('login works through the real router', function () use ($base) {
    http_login($base);
    [$status, $body] = http('GET', $base . '/admin/content');

    assert_eq(200, $status, 'authenticated request should render');
    assert_contains('Micro CMS', $body);
});

t('destructive endpoints reject GET', function () use ($base) {
    http_login($base);

    foreach (['/admin/content/remove?id=1&type=page', '/admin/menu/remove?menu=main'] as $path) {
        [$status] = http('GET', $base . $path);
        assert_eq(405, $status, "{$path} must be POST only");
    }
});

t('destructive endpoints reject a tokenless POST', function () use ($base) {
    http_login($base);

    [$status] = http('POST', $base . '/admin/content/remove', true, ['id' => 1, 'type' => 'page']);
    assert_eq(302, $status, 'must redirect without deleting');
});

t('destructive endpoints trash, restore and purge', function () use ($base, $cookieJar) {
    http_login($base);

    // Create throwaway content to remove.
    $pdo = db();
    $now = time();
    $pdo->prepare("
        INSERT INTO content (type, slug, title, status, body, created_at, updated_at, published_at)
        VALUES ('page', 'throwaway-page', 'Throwaway', 'published', '[]', :now, :now, :now)
    ")->execute(['now' => $now]);
    $id = (int) $pdo->lastInsertId();

    [, $page] = http('GET', $base . '/admin/content?type=page');

    if (!preg_match('/name="_token" value="([^"]+)"/', $page, $matches)) {
        throw new RuntimeException('no CSRF token found on the content list');
    }

    $token = $matches[1];

    // Delete is a move to the trash, not a purge.
    [$status] = http('POST', $base . '/admin/content/remove', true, [
        'id'     => $id,
        'type'   => 'page',
        '_token' => $token,
    ]);

    assert_eq(302, $status);

    $state = db()->prepare("SELECT deleted_at FROM content WHERE id = :id");
    $state->execute(['id' => $id]);
    assert_true($state->fetchColumn() !== null, 'the item is trashed, not deleted');
    // Release the read lock: the server writes to the same database next.
    $state->closeCursor();

    // Restore brings it back.
    [$status] = http('POST', $base . '/admin/content/restore', true, [
        'id'     => $id,
        'type'   => 'page',
        '_token' => $token,
    ]);

    assert_eq(302, $status);

    $state->execute(['id' => $id]);
    assert_eq(null, $state->fetchColumn() ?: null, 'restore clears deleted_at');
    $state->closeCursor();

    // Trash it again and purge it from the trash — the realistic flow, and the
    // one where remove.php has to load a row the admin list no longer shows.
    http('POST', $base . '/admin/content/remove', true, [
        'id'     => $id,
        'type'   => 'page',
        '_token' => $token,
    ]);

    // Purge removes the row for good.
    http('POST', $base . '/admin/content/remove', true, [
        'id'      => $id,
        'type'    => 'page',
        'purge'   => 1,
        '_token'  => $token,
    ]);

    $exists = db()->prepare("SELECT COUNT(*) FROM content WHERE id = :id");
    $exists->execute(['id' => $id]);
    assert_eq(0, (int) $exists->fetchColumn(), 'purge removes the row');
});

t('the content list links live items out and drafts to a preview', function () use ($base, $cookieJar) {
    http_login($base);

    $pdo = db();
    $now = time();

    // Unique slugs so each row can be picked out of the list by id.
    $insert = $pdo->prepare("
        INSERT INTO content (type, slug, title, status, body, created_at, updated_at, published_at)
        VALUES ('page', :slug, :title, :status, '[]', :now, :now, :published)
    ");

    $insert->execute(['slug' => 'list-live', 'title' => 'List Live', 'status' => 'published', 'now' => $now, 'published' => $now]);
    $liveId = (int) $pdo->lastInsertId();

    $insert->execute(['slug' => 'list-draft', 'title' => 'List Draft', 'status' => 'draft', 'now' => $now, 'published' => null]);
    $draftId = (int) $pdo->lastInsertId();

    [, $page] = http('GET', $base . '/admin/content?type=page&q=list-');

    $rowFor = function (int $id) use ($page): string {
        if (!preg_match('#<tr data-content-id="' . $id . '">(.*?)</tr>#s', $page, $matches)) {
            throw new RuntimeException("row {$id} not found in the content list");
        }

        return $matches[1];
    };

    $liveRow  = $rowFor($liveId);
    $draftRow = $rowFor($draftId);

    // A published item opens the front end; an unpublished one needs the token.
    preg_match('#<a href="([^"]+)"[^>]*aria-label="View"#s', $liveRow, $viewMatch);
    assert_true(isset($viewMatch[1]), 'the published row has a view link');
    assert_false(str_contains($viewMatch[1], 'preview='), 'the live view link has no preview token');

    preg_match('#<a href="([^"]+)"[^>]*aria-label="Preview"#s', $draftRow, $previewMatch);
    assert_true(isset($previewMatch[1]), 'the draft row has a preview link');
    assert_true(str_contains($previewMatch[1], 'preview='), 'the preview link carries a token');

    // Actions run edit, view, duplicate, delete.
    $positions = array_map(
        static fn(string $needle) => strpos($liveRow, $needle),
        ['aria-label="Edit"', 'aria-label="View"', 'aria-label="Duplicate"', 'aria-label="Move to trash"']
    );

    assert_true(!in_array(false, $positions, true), 'an editable published row shows all four actions');
    assert_true(
        $positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3],
        'the actions are ordered edit, view, duplicate, delete'
    );
});

t('public forms require a signed token', function () use ($base) {
    [$status, $body] = http('POST', $base . '/form-submit', false, ['form_type' => 'contact']);

    assert_eq(419, $status);
    assert_contains('expired', $body);
});

t('fresh signed tokens are issued by /form-token', function () use ($base) {
    [$status, $body] = http('GET', $base . '/form-token?form_type=contact', false);

    assert_eq(200, $status);
    assert_contains('_form_token', $body);
    assert_contains('contact', $body);
});

t('a form POST with a fresh token is accepted', function () use ($base) {
    [, $tokenBody] = http('GET', $base . '/form-token?form_type=contact', false);
    $token = json_decode($tokenBody, true)['token'] ?? '';

    [$status, $body] = http('POST', $base . '/form-submit', false, [
        'form_type'   => 'contact',
        '_form_token' => $token,
        'name'        => 'Test Person',
        'email'       => 'test@example.com',
        'phone'       => '555',
        'message'     => 'Hello',
    ]);

    assert_eq(200, $status, 'valid submission must succeed: ' . $body);
});

t('no unpublished content leaks into cached HTML', function () use ($base) {
    // Hide a page from the public site.
    $pdo = db();
    $pdo->exec("UPDATE content SET status = 'draft' WHERE slug = 'about'");

    test_clear_cache_files();

    [$status] = http('GET', $base . '/about/', false);
    assert_eq(404, $status, 'a draft must 404 for anonymous visitors');

    $cached = glob(STORAGE_PATH . '/cache/about*.html') ?: [];
    assert_count(0, $cached, 'a draft must never be written to the public cache');

    $pdo->exec("UPDATE content SET status = 'published' WHERE slug = 'about'");
});

// ---------------------------------------------------------------------------
// Preview mode
// ---------------------------------------------------------------------------

t('being signed in without a token is not preview', function () use ($base) {
    http_login($base);


    $id = http_seed_content('signed-in-draft', 'draft');

    [$status] = http('GET', $base . '/signed-in-draft/');
    assert_eq(404, $status, 'a signed-in browse must behave like a visitor');

    // Signed-in traffic is also kept out of the shared cache: the cache is
    // keyed by path only, so an editor's render must never become the public
    // page for that URL.
    test_clear_cache_files();
    http('GET', $base . '/about/');
    assert_count(0, glob(STORAGE_PATH . '/cache/about*.html') ?: [], 'signed-in visits are not cached');

    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

t('a preview token unlocks drafts and is marked as preview', function () use ($base, $cookieJar) {
    http_login($base);

    $token = http_preview_token();

    $id = http_seed_content('preview-draft-page', 'draft');

    [$status, $body, $headers] = http('GET', $base . '/preview-draft-page/?preview=' . $token);

    assert_eq(200, $status, 'preview must render the draft');
    assert_contains('cms-preview-bar', $body, 'the preview bar should be present');
    assert_contains('Draft', $body, 'the bar should name the status');
    assert_contains('X-Preview: 1', $headers, 'preview responses are labelled');
    assert_contains('no-store', $headers, 'preview responses are not cacheable');

    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

t('a preview request is never written to the shared cache', function () use ($base) {
    http_login($base);
    $token = http_preview_token();

    test_clear_cache_files();

    [$status] = http('GET', $base . '/about/?preview=' . $token);
    assert_eq(200, $status);

    $cached = glob(STORAGE_PATH . '/cache/about*.html') ?: [];
    assert_count(0, $cached, 'preview output must stay out of the cache');

    // The public page is still the cached-free, anonymous version.
    [$anonStatus, $anonBody] = http('GET', $base . '/about/', false);
    assert_eq(200, $anonStatus);
    assert_not_contains('cms-preview-bar', $anonBody, 'visitors must never see the preview bar');
});

t('a wrong preview token is ignored', function () use ($base) {
    http_login($base);

    $id = http_seed_content('bad-token-draft', 'draft');

    [$status] = http('GET', $base . '/bad-token-draft/?preview=deadbeef');
    assert_eq(404, $status, 'a forged token must not unlock drafts');

    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

t('a cached page is never served to a signed-in user', function () use ($base) {
    // Warm the cache as a visitor.
    test_clear_cache_files();
    http('GET', $base . '/about/', false);

    assert_count(1, glob(STORAGE_PATH . '/cache/about*.html') ?: [], 'precondition: the page is cached');

    // An editor's own request must bypass it (so their session/preview state
    // can never be fed a page rendered for someone else).
    [, , $headers] = http('GET', $base . '/about/?preview=' . http_preview_token());
    assert_not_contains('X-Cache: HIT', $headers, 'preview must not hit the cache');
});


// ---------------------------------------------------------------------------
// Bulk actions
// ---------------------------------------------------------------------------

/**
 * A CSRF token from any admin page, using the current session.
 */
function http_csrf_token(string $base, string $path = '/admin/content?type=page'): string
{
    [, $page] = http('GET', $base . $path);

    if (!preg_match('/name="_token" value="([^"]+)"/', $page, $matches)) {
        throw new RuntimeException("no CSRF token on {$path}");
    }

    return $matches[1];
}

t('bulk actions require a valid session', function () use ($base) {
    $id = http_seed_content('bulk-anon', 'draft');

    [$status] = http('POST', $base . '/admin/content/bulk', false, [
        'bulk_action' => 'publish',
        'type'        => 'page',
        'ids'         => [$id],
    ]);

    assert_eq(302, $status, 'anonymous requests are bounced to login');
    assert_eq('draft', http_content_status($id), 'nothing changed');
});

t('bulk actions reject a missing token', function () use ($base) {
    http_login($base);

    $id = http_seed_content('bulk-no-token', 'draft');

    [$status] = http('POST', $base . '/admin/content/bulk', true, [
        'bulk_action' => 'publish',
        'type'        => 'page',
        'ids'         => [$id],
    ]);

    assert_eq(302, $status, 'the CSRF gate stops it');
    assert_eq('draft', http_content_status($id), 'nothing changed');
});

t('publishing a selection sets every item live', function () use ($base) {
    http_login($base);

    $first  = http_seed_content('bulk-publish-one', 'draft');
    $second = http_seed_content('bulk-publish-two', 'draft');

    [$status, , $headers] = http('POST', $base . '/admin/content/bulk', true, [
        '_token'      => http_csrf_token($base),
        'bulk_action' => 'publish',
        'type'        => 'page',
        'ids'         => [$first, $second],
    ]);

    assert_eq(302, $status, 'the endpoint redirects back to the list');
    assert_eq('published', http_content_status($first));
    assert_eq('published', http_content_status($second));
});

t('drafting a selection clears the publish date and keeps history', function () use ($base) {
    http_login($base);

    $id = http_seed_content('bulk-draft-me', 'draft');

    // Publish first so there is a previous state worth snapshotting.
    http('POST', $base . '/admin/content/bulk', true, [
        '_token'      => http_csrf_token($base),
        'bulk_action' => 'publish',
        'type'        => 'page',
        'ids'         => [$id],
    ]);

    http('POST', $base . '/admin/content/bulk', true, [
        '_token'      => http_csrf_token($base),
        'bulk_action' => 'draft',
        'type'        => 'page',
        'ids'         => [$id],
    ]);

    assert_eq('draft', http_content_status($id));

    $row = db()->prepare("SELECT published_at FROM content WHERE id = :id");
    $row->execute(['id' => $id]);

    assert_eq(null, $row->fetchColumn(), 'a draft has no publish date');
    assert_true(count_content_versions($id) >= 1, 'the bulk edit is undoable');
});

t('bulk actions refuse an unknown action and an empty selection', function () use ($base) {
    http_login($base);

    $id = http_seed_content('bulk-refuse', 'draft');

    http('POST', $base . '/admin/content/bulk', true, [
        '_token'      => http_csrf_token($base),
        'bulk_action' => 'explode',
        'type'        => 'page',
        'ids'         => [$id],
    ]);

    assert_eq('draft', http_content_status($id), 'an unknown action changes nothing');

    [$status] = http('POST', $base . '/admin/content/bulk', true, [
        '_token'      => http_csrf_token($base),
        'bulk_action' => 'publish',
        'type'        => 'page',
        'ids'         => [],
    ]);

    assert_eq(302, $status, 'an empty selection is bounced back');
});

t('the bulk selection is capped', function () use ($base) {
    http_login($base);

    $id = http_seed_content('bulk-cap', 'draft');

    // 250 ids exceeds the documented limit of 200.
    $ids = array_merge([$id], range(100000, 100248));

    http('POST', $base . '/admin/content/bulk', true, [
        '_token'      => http_csrf_token($base),
        'bulk_action' => 'publish',
        'type'        => 'page',
        'ids'         => $ids,
    ]);

    assert_eq('draft', http_content_status($id), 'an oversized selection is refused');
});

t('an author cannot publish through bulk actions', function () use ($base, $cookieJar) {
    // Create an author with a known password.
    db()->prepare("
        INSERT OR IGNORE INTO users (username, email, password_hash, role, created_at)
        VALUES ('http-author', 'http-author@example.com', :hash, 'author', :now)
    ")->execute(['hash' => password_hash('author-pass-123', PASSWORD_DEFAULT), 'now' => time()]);

    $id = http_seed_content('bulk-author-draft', 'draft');

    // Sign in as the author (a fresh jar, then restore the admin session after).
    file_put_contents($cookieJar, '');

    [, $loginPage] = http('GET', $base . '/admin/login');
    preg_match('/name="_token" value="([^"]+)"/', $loginPage, $matches);

    http('POST', $base . '/admin/login', true, [
        'username' => 'http-author',
        'password' => 'author-pass-123',
        '_token'   => $matches[1],
    ]);

    http('POST', $base . '/admin/content/bulk', true, [
        '_token'      => http_csrf_token($base),
        'bulk_action' => 'publish',
        'type'        => 'page',
        'ids'         => [$id],
    ]);

    assert_eq('draft', http_content_status($id), 'authors may not publish, even in bulk');
});

t('bulk actions are audited', function () use ($base) {
    http_login($base);

    db()->exec("DELETE FROM activity_log");

    $id = http_seed_content('bulk-audited', 'draft');

    http('POST', $base . '/admin/content/bulk', true, [
        '_token'      => http_csrf_token($base),
        'bulk_action' => 'publish',
        'type'        => 'page',
        'ids'         => [$id],
    ]);

    $result = list_activity(['action' => 'content.bulk_publish']);

    assert_eq(1, $result['total'], 'one audit entry per bulk action');
    assert_contains('1 item(s)', (string) $result['items'][0]['summary']);
});

t('the admin renders in the language of the signed-in user', function () use ($base) {
    db()->prepare("UPDATE users SET ui_language = 'sv' WHERE username = 'demo'")->execute();

    http_login($base);

    [$status, $utilities] = http('GET', $base . '/admin/utilities');

    assert_eq(200, $status);
    assert_contains('Rensa cache', $utilities, 'utilities headings are translated');
    assert_not_contains('Clear Cache', $utilities, 'the English heading is gone');

    [, $editor] = http('GET', $base . '/admin/content/edit?type=page');
    assert_contains('Utkast', $editor, 'the status dropdown is translated');

    [, $list] = http('GET', $base . '/admin/content?type=page');
    assert_contains('Utkast', $list, 'status tabs use the translated label');

    // Messages follow too: an unknown utility action answers in Swedish.
    http('POST', $base . '/admin/utilities', true, [
        '_token'         => http_csrf_token($base),
        'utility_action' => 'nonsense',
    ]);

    [, $after] = http('GET', $base . '/admin/utilities');
    assert_contains('Okänd åtgärd', $after, 'toast messages are translated');

    db()->prepare("UPDATE users SET ui_language = 'en' WHERE username = 'demo'")->execute();
    http_login($base);
});

t('the utilities page can rebuild the search index', function () use ($base) {
    http_login($base);

    db()->exec("UPDATE content SET search_text = NULL");

    [$status] = http('POST', $base . '/admin/utilities', true, [
        '_token'         => http_csrf_token($base, '/admin/utilities'),
        'utility_action' => 'search_reindex',
    ]);

    assert_eq(302, $status);

    $unindexed = (int) db()->query("SELECT COUNT(*) FROM content WHERE search_text IS NULL OR search_text = ''")->fetchColumn();
    assert_eq(0, $unindexed, 'the action rebuilt the index');

    [, $page] = http('GET', $base . '/admin/utilities');
    assert_contains('Search index rebuilt', $page, 'the toast reports the result');
});

t('<html lang> falls back to the default when site_language is unset', function () use ($base) {
    $original = (string) get_setting('site_language', 'en');

    db()->prepare("DELETE FROM settings WHERE `key` = 'site_language'")->execute();
    settings_cache_clear();

    test_clear_cache_files();
    [$status, $body] = http('GET', $base . '/about/', false);

    assert_eq(200, $status);
    assert_contains("<html lang='en'>", $body);

    set_setting('site_language', $original);
    test_clear_cache_files();
});

t('a missing URL answers 404 with noindex and falls back to the core component', function () use ($base) {
    test_clear_cache_files();

    // The seeded 404 page answers first, and must not be indexable.
    [$status, $body] = http('GET', $base . '/no-such-page-xyz/', false);

    assert_eq(404, $status);
    assert_contains("name='robots' content='noindex, follow'", $body);

    // Without it the router synthesises a page whose only component is the
    // core fallback, so that file must render rather than warn.
    db()->exec("DELETE FROM content WHERE slug = '404' AND type = 'page'");

    test_clear_cache_files();
    [$status, $body] = http('GET', $base . '/no-such-page-xyz/', false);

    assert_eq(404, $status);
    assert_contains('cms-not-found', $body, 'the core 404 component renders');
    assert_not_contains('component not found', $body);
    assert_contains("name='robots' content='noindex, follow'", $body);
});

// ---------------------------------------------------------------------------
// Password reset
// ---------------------------------------------------------------------------

t('the forgot-password page answers the same for a known and an unknown address', function () use ($base, $cookieJar) {
    file_put_contents($cookieJar, '');
    db()->exec("DELETE FROM form_rate_limits");
    @unlink(STORAGE_PATH . '/logs/forms.log');

    [$status, $page] = http('GET', $base . '/admin/forgot-password', false);
    assert_eq(200, $status, 'the page is public');
    assert_contains('name="email"', $page);

    [, $login] = http('GET', $base . '/admin/login', false);
    assert_contains('forgot-password', $login, 'the login page links to it');

    // A known address ...
    $token = http_csrf_token($base, '/admin/forgot-password');
    [$status, $known] = http('POST', $base . '/admin/forgot-password', true, [
        '_token' => $token,
        'email'  => 'admin@example.com',
    ]);
    assert_eq(200, $status);
    assert_contains('reset link is on its way', $known);

    // ... and an unknown one render the same confirmation.
    $token = http_csrf_token($base, '/admin/forgot-password');
    [$status, $unknown] = http('POST', $base . '/admin/forgot-password', true, [
        '_token' => $token,
        'email'  => 'nobody@example.com',
    ]);
    assert_eq(200, $status);
    assert_contains('reset link is on its way', $unknown);

    $log = (string) @file_get_contents(STORAGE_PATH . '/logs/forms.log');
    assert_contains('admin@example.com', $log, 'the known address gets a logged mail');
    assert_not_contains('nobody@example.com', $log, 'the unknown one gets none');
});

t('an emailed reset link sets a new password exactly once', function () use ($base, $cookieJar) {
    file_put_contents($cookieJar, '');
    db()->exec("DELETE FROM form_rate_limits");
    @unlink(STORAGE_PATH . '/logs/forms.log');

    // A throwaway account, so the demo login other tests rely on is untouched.
    db()->prepare("
        INSERT INTO users (username, email, password_hash, role, created_at)
        VALUES ('reset-target', 'reset-target@example.com', :hash, 'author', :now)
    ")->execute(['hash' => password_hash('old-password-123', PASSWORD_DEFAULT), 'now' => time()]);

    $token = http_csrf_token($base, '/admin/forgot-password');
    http('POST', $base . '/admin/forgot-password', true, [
        '_token' => $token,
        'email'  => 'reset-target@example.com',
    ]);

    $log = (string) @file_get_contents(STORAGE_PATH . '/logs/forms.log');
    assert_true((bool) preg_match('#token=([a-f0-9]{64})#', $log, $matches), 'the log carries a link');
    $raw = $matches[1];

    // An invalid token is refused, and shows no form.
    [$status, $invalid] = http('GET', $base . '/admin/reset-password/?token=' . str_repeat('a', 64), false);
    assert_eq(200, $status);
    assert_contains('invalid or has expired', $invalid);
    assert_not_contains('name="password"', $invalid);

    // The real link shows the form.
    [$status, $page] = http('GET', $base . '/admin/reset-password/?token=' . $raw, true);
    assert_eq(200, $status);
    assert_contains('name="password"', $page);

    preg_match('/name="_token" value="([^"]+)"/', $page, $csrf);

    [$status] = http('POST', $base . '/admin/reset-password', true, [
        '_token'           => $csrf[1],
        'token'            => $raw,
        'password'         => 'new-password-456',
        'password_confirm' => 'new-password-456',
    ]);

    assert_eq(302, $status, 'a completed reset redirects to login');

    $hash = (string) db()->query("SELECT password_hash FROM users WHERE username = 'reset-target'")->fetchColumn();
    assert_true(password_verify('new-password-456', $hash), 'the new password is stored');

    // The link is spent.
    assert_eq(null, password_reset_find($raw), 'the link cannot be used twice');

    db()->exec("DELETE FROM users WHERE username = 'reset-target'");
});

t('an editor autosave stores a draft version without saving the content', function () use ($base) {
    http_login($base);

    $id = http_seed_content('editor-autosave', 'published', time());

    // Make the stored row clearly older than the autosave that follows, so the
    // "unsaved draft" comparison is unambiguous.
    db()->prepare("UPDATE content SET updated_at = :time WHERE id = :id")
        ->execute(['time' => time() - 120, 'id' => $id]);

    $token = http_csrf_token($base, '/admin/content/edit?id=' . $id . '&type=page');

    [$status, $body] = http('POST', $base . '/admin/content/save', true, [
        '_token'   => $token,
        'autosave' => '1',
        'id'       => $id,
        'type'     => 'page',
        'title'    => 'Autosaved work in progress',
        'slug'     => 'editor-autosave',
        'status'   => 'published',
    ]);

    assert_eq(200, $status);
    assert_contains('"ok":true', $body, 'the autosave answers JSON');

    $version = latest_content_autosave($id);
    assert_true($version !== null, 'an autosave version exists');
    assert_eq('autosave', $version['reason']);
    assert_eq('Autosaved work in progress', $version['title']);

    // The live row keeps the last real save.
    assert_eq('Editor autosave', load_content_by_id($id)['title']);

    // Reopening the editor offers the draft back, linking at that version.
    // (The banner text alone would also appear in the page's translation JSON.)
    [, $editor] = http('GET', $base . '/admin/content/edit?id=' . $id . '&type=page');
    assert_contains('&amp;version=' . $version['id'], $editor, 'the editor links to the autosave version');

    // A real save supersedes the draft, so the offer goes away.
    http('POST', $base . '/admin/content/save', true, [
        '_token' => $token,
        'id'     => $id,
        'type'   => 'page',
        'title'  => 'Now saved properly',
        'slug'   => 'editor-autosave',
        'status' => 'published',
    ]);

    [, $saved] = http('GET', $base . '/admin/content/edit?id=' . $id . '&type=page');
    assert_contains('Now saved properly', $saved);
    assert_not_contains('&amp;version=' . $version['id'], $saved, 'saving clears the offer');

    delete_content_versions($id);
    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

// ---------------------------------------------------------------------------
// Shut the server down
// ---------------------------------------------------------------------------
proc_terminate($server);
proc_close($server);

exit(test_summary());

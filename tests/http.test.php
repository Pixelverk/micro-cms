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

t('destructive endpoints accept a valid POST', function () use ($base, $cookieJar) {
    http_login($base);

    // Create throwaway content to delete.
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

    [$status] = http('POST', $base . '/admin/content/remove', true, [
        'id'     => $id,
        'type'   => 'page',
        '_token' => $matches[1],
    ]);

    assert_eq(302, $status);

    $exists = db()->prepare("SELECT COUNT(*) FROM content WHERE id = :id");
    $exists->execute(['id' => $id]);
    assert_eq(0, (int) $exists->fetchColumn(), 'the item must be deleted');
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

// ---------------------------------------------------------------------------
// Shut the server down
// ---------------------------------------------------------------------------
proc_terminate($server);
proc_close($server);

exit(test_summary());

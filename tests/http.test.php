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
        // A CURLFile means a multipart upload: curl builds the body from the
        // array itself, so the values must not be query-encoded first.
        $multipart = false;

        foreach ($post as $value) {
            if ($value instanceof CURLFile) {
                $multipart = true;
                break;
            }
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
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

function http_login(string $base, string $username = 'admin', string $password = 'admin'): void
{
    global $cookieJar;

    file_put_contents($cookieJar, '');

    [, $loginPage] = http('GET', $base . '/admin/login');

    if (!preg_match('/name="_token" value="([^"]+)"/', $loginPage, $matches)) {
        throw new RuntimeException('login page did not contain a CSRF token');
    }

    [$status] = http('POST', $base . '/admin/login', true, [
        'username' => $username,
        'password' => $password,
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

t('every layout the theme ships is reachable on the front end', function () use ($base) {
    // One seeded URL per layout file. The archive layouts come from the demo's
    // taxonomy terms; search has its own route and is never a page.
    $paths = [
        '/'                          => 'default',
        '/blog/welcome-to-our-blog/' => 'blog',
        '/portfolio/project-one/'    => 'portfolio',
        '/privacy/'                  => 'policy',
        '/landing/'                  => 'landing',
        '/category/news/'            => 'blog-archive',
        '/category/design/'          => 'taxonomy',
        '/search?q=launch'           => 'search',
    ];

    foreach ($paths as $path => $layout) {
        [$status, $body] = http('GET', $base . $path, false);

        assert_eq(200, $status, "{$layout} renders at {$path}");
        assert_contains('<html', $body, "{$layout} returned a page");
    }

    // The two archive layouts share their body markup, so they are told apart
    // by the class the blog one adds to its main landmark.
    [, $blogArchive] = http('GET', $base . '/category/news/', false);
    assert_contains('blog-archive-page', $blogArchive, 'the blog archive layout renders the term');
    assert_contains('<h1>News</h1>', $blogArchive, 'as the page heading');

    [, $archive] = http('GET', $base . '/category/design/', false);
    assert_contains('Type: category', $archive, 'so does the generic archive layout');
    assert_not_contains('blog-archive-page', $archive, 'which is not the blog one');

    // The landing layout deliberately leaves the site chrome out.
    [$status, $home]      = http('GET', $base . '/', false);
    [$status, $landing]   = http('GET', $base . '/landing/', false);

    assert_eq(200, $status);
    assert_contains('navbar', $home, 'a normal page carries the header');
    assert_not_contains('navbar', $landing, 'a landing page does not');
});

t('every page has one h1 and a skip link through to the main content', function () use ($base) {
    // Covers one URL per layout plus the pages that combine several sections,
    // which is where a second <h1> used to appear.
    $paths = [
        '/', '/about/', '/services/', '/pricing/', '/faq/', '/blog/',
        '/portfolio/', '/privacy/', '/contact/', '/landing/',
        '/blog/welcome-to-our-blog/', '/portfolio/project-one/',
        '/category/news/', '/category/design/', '/search?q=launch',
    ];

    foreach ($paths as $path) {
        [$status, $body] = http('GET', $base . $path, false);

        assert_eq(200, $status, "{$path} renders");
        assert_eq(1, substr_count($body, '<h1'), "{$path} has exactly one h1");
        assert_contains('class="skip-link" href="#main-content"', $body, "{$path} offers a skip link");
        assert_contains('<main id="main-content"', $body, "{$path} marks the skip target");

        assert_true(
            strpos($body, 'class="skip-link"') < strpos($body, '<main id="main-content"'),
            "{$path} puts the skip link before the main landmark"
        );
    }

    // The synthesised 404 renders through the same layouts.
    [$status, $missing] = http('GET', $base . '/definitely-not-a-page/', false);

    assert_eq(404, $status);
    assert_eq(1, substr_count($missing, '<h1'), 'the 404 page has exactly one h1');
});

t('the navigation marks the current page and its section', function () use ($base) {
    // Markup, not script: a visitor without JavaScript sees the same thing.
    [$status, $about] = http('GET', $base . '/about/', false);

    assert_eq(200, $status);
    assert_true(
        (bool) preg_match('/<a class="nav-link active"[^>]*>\s*About\s*</s', $about),
        'the current page is the active link'
    );
    assert_eq(1, substr_count($about, 'aria-current="page"'), 'exactly one link is the current page');
    assert_not_contains('link.style.fontWeight', $about, 'the old inline-style script is gone');

    // The blog post marks itself and the section it sits in.
    [$status, $post] = http('GET', $base . '/blog/welcome-to-our-blog/', false);

    assert_eq(200, $status);
    assert_eq(1, substr_count($post, 'aria-current="page"'), 'the post is the current page');
    assert_true(
        (bool) preg_match('/<a class="nav-link active dropdown-toggle"/', $post),
        'and Blog, the section it belongs to, is active'
    );

    // The footer carries the same state.
    [$status, $privacy] = http('GET', $base . '/privacy/', false);

    assert_eq(200, $status);
    assert_contains('class="link-light small active"', $privacy, 'the footer marks its own page too');
});

t('a hidden item leaves the menu and stays in the editor', function () use ($base) {
    $menu     = get_menu('main');
    $original = $menu['items'];

    $withHidden = static function (bool $hidden) use ($original): array {
        return array_merge($original, [[
            'type'     => 'page',
            'label'    => 'Parked Link',
            'slug'     => 'pricing',
            'hidden'   => $hidden,
            'children' => [],
        ]]);
    };

    save_menu(['label' => $menu['label'], 'slug' => 'main', 'items' => $withHidden(true)]);

    [, $page] = http('GET', $base . '/', false);
    assert_not_contains('Parked Link', $page, 'a hidden item is not rendered');

    // The editor keeps it, so a parked branch is still editable.
    http_login($base);
    [, $editor] = http('GET', $base . '/admin/menu/edit?menu=main', true);
    assert_contains('Parked Link', $editor, 'the editor still shows it');

    // Unhide, and it is back on the site.
    save_menu(['label' => $menu['label'], 'slug' => 'main', 'items' => $withHidden(false)]);
    [, $page] = http('GET', $base . '/', false);
    assert_contains('Parked Link', $page, 'unhiding brings it back');

    // Leave the demo menu exactly as it was found.
    save_menu(['label' => $menu['label'], 'slug' => 'main', 'items' => $original]);
});

t('the manifest is served and the pages link to it', function () use ($base) {
    [$status, $body, $headers] = http('GET', $base . '/site.webmanifest');

    assert_eq(200, $status, 'the manifest is served');
    assert_contains('application/manifest+json', $headers, 'with the manifest content type');

    $manifest = json_decode($body, true);

    assert_true(is_array($manifest), 'and it parses as JSON');
    assert_true(!empty($manifest['name']), 'it names the site');
    assert_eq('/', $manifest['start_url']);
    assert_eq('standalone', $manifest['display']);
    assert_true(!empty($manifest['icons']), 'and lists icons');

    // The icons it points at have to be reachable.
    foreach ($manifest['icons'] as $icon) {
        $path = parse_url($icon['src'], PHP_URL_PATH) ?: '';
        [$iconStatus] = http('GET', $base . $path);
        assert_eq(200, $iconStatus, 'icon ' . $path . ' loads');
    }

    // A rendered page and a post both link to it, and only a post has times.
    [, $page] = http('GET', $base . '/about');
    assert_contains("rel='manifest'", $page, 'the page links the manifest');
    assert_contains("rel='apple-touch-icon'", $page, 'and the apple icon');
    assert_contains("name='theme-color'", $page, 'and the browser colour');
    assert_not_contains('article:published_time', $page, 'a page has no publication time');

    [, $post] = http('GET', $base . '/blog/welcome-to-our-blog');
    assert_contains("property='article:published_time'", $post, 'a post says when it was published');
    assert_contains("property='article:section'", $post, 'and which section it is in');

    // Virtual documents never belong in the sitemap.
    [, $sitemap] = http('GET', $base . '/sitemap.xml');
    assert_not_contains('site.webmanifest', $sitemap, 'the manifest is not in the sitemap');
    assert_not_contains('robots.txt', $sitemap, 'nor is robots.txt');
});

t('categories and tags delete in bulk from their lists', function () use ($base) {
    http_login($base);

    $pdo = db();

    // Terms of this test's own, so the assertion does not depend on what the
    // package tests before it left in the table.
    $make = static function (string $kind, string $slug) use ($pdo): int {
        $pdo->prepare("
            INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, created_at, updated_at)
            VALUES (:kind, 'blog_post', :name, :slug, :now, :now)
        ")->execute(['kind' => $kind, 'name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'now' => time()]);

        return (int) $pdo->lastInsertId();
    };

    $exists = static fn(int $id): bool => (bool) db()->query("SELECT COUNT(*) FROM taxonomy WHERE id = {$id}")->fetchColumn();

    $first  = $make('category', 'bulk-http-one');
    $second = $make('category', 'bulk-http-two');
    $tag    = $make('tag', 'bulk-http-tag');

    // The list offers the toolbar and a checkbox per row.
    [$status, $page] = http('GET', $base . '/admin/category', true);

    assert_eq(200, $status);
    assert_contains('id="bulk-form"', $page, 'the bulk toolbar is on the page');
    assert_contains('class="bulk-row" name="ids[]"', $page, 'every row offers a checkbox');
    assert_contains('form="bulk-form"', $page, 'which joins the toolbar form');

    $token = http_csrf_token($base, '/admin/category');

    [$status] = http('GET', $base . '/admin/category/bulk', true);
    assert_eq(405, $status, 'the endpoint only answers POST');

    [$status] = http('POST', $base . '/admin/category/bulk', true, ['_token' => $token]);
    assert_eq(302, $status);
    assert_true($exists($first) && $exists($second), 'an empty selection deletes nothing');

    // Two categories go; an id that does not resolve is skipped, not fatal.
    [$status] = http('POST', $base . '/admin/category/bulk', true, [
        '_token' => $token,
        'ids'    => [$first, $second, 999999],
    ]);

    assert_eq(302, $status);
    assert_false($exists($first), 'the first category is gone');
    assert_false($exists($second), 'and the second');
    assert_true($exists($tag), 'the tag was not touched by the category endpoint');

    [, $page] = http('GET', $base . '/admin/category', true);
    $text = html_entity_decode($page, ENT_QUOTES);
    assert_contains('2 item(s) removed', $text, 'the toast reports how many went');
    assert_contains('1 skipped', $text, 'and what was skipped');

    // The single-row button still goes through the same delete.
    $row = $make('category', 'bulk-http-row');

    [$status] = http('POST', $base . '/admin/category/remove', true, [
        '_token' => $token,
        'id'     => $row,
    ]);

    assert_eq(302, $status);
    assert_false($exists($row), 'the row button deletes its own term');

    [, $page] = http('GET', $base . '/admin/category', true);
    assert_contains('deleted', html_entity_decode($page, ENT_QUOTES), 'and reports it');

    // The tag endpoint takes its own kind, and only its own.
    [$status] = http('POST', $base . '/admin/tag/bulk', true, [
        '_token' => http_csrf_token($base, '/admin/tag'),
        'ids'    => [$tag],
    ]);

    assert_eq(302, $status);
    assert_false($exists($tag), 'the tag is gone');
});

t('the taxonomy bulk endpoint needs the capability', function () use ($base, $cookieJar) {
    db()->exec("
        INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, created_at, updated_at)
        VALUES ('category', 'blog_post', 'Bulk Guard', 'bulk-guard', " . time() . ", " . time() . ")
    ");

    $id = (int) db()->lastInsertId();

    db()->prepare("
        INSERT OR IGNORE INTO users (username, email, password_hash, role, created_at)
        VALUES ('http-author-tax', 'http-author-tax@example.com', :hash, 'author', :now)
    ")->execute(['hash' => password_hash('author-tax-pass', PASSWORD_DEFAULT), 'now' => time()]);

    file_put_contents($cookieJar, '');

    [, $loginPage] = http('GET', $base . '/admin/login');
    preg_match('/name="_token" value="([^"]+)"/', $loginPage, $matches);

    http('POST', $base . '/admin/login', true, [
        'username' => 'http-author-tax',
        'password' => 'author-tax-pass',
        '_token'   => $matches[1],
    ]);

    [$status, $page] = http('GET', $base . '/admin/category', true);

    assert_true($status !== 200, 'an author cannot open the categories page at all');
    assert_not_contains('id="bulk-form"', $page, 'so the toolbar is not reachable');

    http('POST', $base . '/admin/category/bulk', true, [
        '_token' => $base ? http_csrf_token($base, '/admin/dashboard') : '',
        'ids'    => [$id],
    ]);

    assert_true(
        (bool) db()->query("SELECT COUNT(*) FROM taxonomy WHERE id = {$id}")->fetchColumn(),
        'and cannot delete through it either'
    );

    db()->prepare("DELETE FROM taxonomy WHERE id = :id")->execute(['id' => $id]);
});

t('every response carries the security baseline', function () use ($base) {
    foreach (['/' => 'front page', '/admin/login' => 'admin page'] as $path => $label) {
        [, , $headers] = http('GET', $base . $path, false);

        assert_contains('X-Content-Type-Options: nosniff', $headers, $label);
        assert_contains('X-Frame-Options: SAMEORIGIN', $headers, $label);
        assert_contains('Referrer-Policy: strict-origin-when-cross-origin', $headers, $label);
    }
});

t('the security baseline survives a cache hit', function () use ($base) {
    test_clear_cache_files();

    http('GET', $base . '/about/', false);
    [, , $headers] = http('GET', $base . '/about/', false);

    assert_contains('X-Cache: HIT', $headers, 'precondition: the page is cached');
    assert_contains('X-Content-Type-Options: nosniff', $headers, 'the firebreak carries the headers');
});

t('uploaded media is served nosniff', function () use ($base) {
    $file = STORAGE_PATH . '/media/header-check.txt';
    file_put_contents($file, 'plain text, not a sniffable image');

    [$status, , $headers] = http('GET', $base . '/media/header-check.txt', false);

    assert_eq(200, $status);
    assert_contains('X-Content-Type-Options: nosniff', $headers);

    @unlink($file);
});

t('the media delete confirmation names what uses the file', function () use ($base) {
    $now = time();

    $used = db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, alt_text, description, created_at, updated_at)
        VALUES ('used-photo.jpg', '2026/03/usedhttp1', 'image/jpeg', 1024, 640, 400,
                '{}', '{}', NULL, 'alt', NULL, :now, :now)
    ");
    $used->execute(['now' => $now]);
    $usedId = (int) db()->lastInsertId();

    db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, alt_text, description, created_at, updated_at)
        VALUES ('spare-photo.jpg', '2026/03/sparehttp', 'image/jpeg', 1024, 640, 400,
                '{}', '{}', NULL, 'alt', NULL, :now, :now)
    ")->execute(['now' => $now]);

    // One page points at the first file, so deleting it would break the page.
    db()->prepare("
        INSERT INTO content (type, title, slug, status, meta, body, created_at, updated_at)
        VALUES ('page', 'Uses The Photo', 'uses-the-photo', 'published', ?, '[]', :now, :now)
    ")->execute([json_encode(['thumbnail' => (string) $usedId]), 'now' => $now]);

    http_login($base);
    [$status, $page] = http('GET', $base . '/admin/media');
    assert_eq(200, $status);

    assert_contains(
        admin_trans('media_delete_confirm_used', [
            'name'  => 'used-photo.jpg',
            'count' => 1,
            'list'  => 'Page “Uses The Photo”',
        ]),
        $page,
        'the confirmation names the page that would break'
    );

    // A file nothing points at keeps the plain confirmation.
    assert_contains(
        admin_trans('media_delete_confirm', ['name' => 'spare-photo.jpg']),
        $page,
        'an unused file is not warned about'
    );
});

t('the media library pages in the database and keeps its filters', function () use ($base) {
    // 27 PNGs, one named in upper case: enough for a second page, and proof
    // that the extension filter is case-insensitive like the tabs are.
    $insert = db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, alt_text, description, created_at, updated_at)
        VALUES (:name, :base, 'image/png', 100, 10, 10, '{}', '{}', NULL, '', NULL, :now, :now)
    ");

    for ($i = 1; $i <= 27; $i++) {
        $insert->execute([
            'name' => sprintf('page-check-%02d.%s', $i, $i === 27 ? 'PNG' : 'png'),
            'base' => '2026/03/pagechk' . sprintf('%02d', $i),
            'now'  => time() + $i,
        ]);
    }

    http_login($base);

    $rowCount = static fn(string $html): int => substr_count($html, 'data-media=');

    [$status, $first] = http('GET', $base . '/admin/media?type=png');
    assert_eq(200, $status);
    assert_eq(24, $rowCount($first), 'one page of rows, not the whole library');
    assert_contains('Page 1 of 2', $first, 'the pager names the page, not "Page 1 of 1s"');
    assert_contains('type=png', $first, 'the pager keeps the type filter');

    [$status, $second] = http('GET', $base . '/admin/media?type=png&page=2');
    assert_eq(200, $status);
    assert_eq(3, $rowCount($second), 'the rest of the filtered rows');
    assert_contains('Page 2 of 2', $second);
});

t('bulk media delete removes only the selected files', function () use ($base) {
    $now = time();

    $insert = db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, alt_text, description, created_at, updated_at)
        VALUES (:name, :base, 'image/jpeg', 100, 10, 10, '{}', '{}', NULL, '', NULL, :now, :now)
    ");

    $ids = [];

    foreach (['bulk-a.jpg', 'bulk-b.jpg', 'bulk-c.jpg'] as $index => $name) {
        $basePath = '2026/03/bulkdel' . $index;
        @mkdir(STORAGE_PATH . '/media/' . $basePath, 0777, true);
        file_put_contents(STORAGE_PATH . '/media/' . $basePath . '/photo.jpg', 'x');

        $insert->execute(['name' => $name, 'base' => $basePath, 'now' => $now + $index]);
        $ids[$name] = (int) db()->lastInsertId();
    }

    // Release the read lock before the server writes to the same database.
    $insert->closeCursor();

    http_login($base);

    [$status, $page] = http('GET', $base . '/admin/media?q=bulk-');
    assert_eq(200, $status);
    assert_contains('id="bulk-form"', $page, 'the toolbar is there to act on a selection');
    assert_eq(3, substr_count($page, 'name="ids[]"'), 'one checkbox per row');

    $token = http_csrf_token($base, '/admin/media?q=bulk-');

    [$status] = http('POST', $base . '/admin/media/bulk', true, [
        'ids'    => [$ids['bulk-a.jpg'], $ids['bulk-b.jpg']],
        '_token' => $token,
    ]);
    assert_eq(302, $status);

    $remaining = db()->query("
        SELECT original_name FROM media WHERE original_name LIKE 'bulk-%' ORDER BY original_name
    ")->fetchAll(PDO::FETCH_COLUMN);

    assert_eq(['bulk-c.jpg'], $remaining, 'the unselected file is left alone');
    assert_false(is_dir(STORAGE_PATH . '/media/2026/03/bulkdel0'), 'a deleted folder is gone');
    assert_true(is_dir(STORAGE_PATH . '/media/2026/03/bulkdel2'), 'the untouched folder stays');

    // An empty selection is refused rather than treated as "everything".
    [$status] = http('POST', $base . '/admin/media/bulk', true, ['ids' => [], '_token' => $token]);
    assert_eq(302, $status);
    assert_eq(1, (int) db()->query("SELECT COUNT(*) FROM media WHERE original_name LIKE 'bulk-%'")->fetchColumn());

    // Leave the scratch media directory as it was found.
    media_delete($ids['bulk-c.jpg']);
});

t('the media inspector reads variant dimensions from the stored sizes', function () use ($base) {
    // Deliberately no file on disk: the numbers below can only come from the
    // row, so a return to probing the filesystem would fail this.
    db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, alt_text, description, created_at, updated_at)
        VALUES ('sizes-check.png', '2026/03/sizeschk', 'image/png', 100, 900, 500, ?, ?, NULL, '', NULL, :now, :now)
    ")->execute([
        json_encode([640 => ['width' => 641, 'height' => 361]]),
        json_encode(['webp' => ['2026/03/sizeschk/sizes-check-640.webp']]),
        'now' => time(),
    ]);

    http_login($base);
    [$status, $page] = http('GET', $base . '/admin/media?q=sizes-check');
    assert_eq(200, $status);
    assert_contains('&quot;width&quot;:641', $page, 'the stored size is used rather than the files on disk');
});

t('HSTS is sent only over HTTPS', function () {
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    assert_false(array_key_exists('Strict-Transport-Security', security_headers()), 'plain HTTP gets no HSTS');

    $_SERVER['HTTPS'] = 'on';
    assert_contains('max-age=', security_headers()['Strict-Transport-Security'] ?? '', 'HTTPS gets HSTS');
    unset($_SERVER['HTTPS']);

    // A proxy that terminates TLS forwards the original scheme.
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    assert_true(array_key_exists('Strict-Transport-Security', security_headers()), 'a TLS-terminating proxy counts');
    unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
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

t('a redirect that would hide a live page is refused, and the page still serves', function () use ($base) {
    test_clear_cache_files();

    // The admin's own check, and the storage layer behind it.
    assert_true(
        in_array('shadows', array_column(redirect_conflicts('about', 'contact'), 'rule'), true),
        'the conflict is detected'
    );

    $refused = false;

    try {
        redirect_save('about', 'contact');
    } catch (RuntimeException $exception) {
        $refused = true;
    }

    assert_true($refused, 'and the save is refused');

    [$status, $page] = http('GET', $base . '/about/', false);

    assert_eq(200, $status, 'the page answers for itself');
    assert_not_contains('X-Cache: HIT', (string) $page, 'and is rendered, not redirected');
    assert_not_contains('Location: /contact/', (string) $page);
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
    db()->prepare("UPDATE users SET ui_language = 'sv' WHERE username = 'admin'")->execute();

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

    db()->prepare("UPDATE users SET ui_language = 'en' WHERE username = 'admin'")->execute();
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

    // Reopening the editor offers the draft, linking into the editor with it
    // loaded. (The banner text alone would also appear in the translation JSON.)
    [, $editor] = http('GET', $base . '/admin/content/edit?id=' . $id . '&type=page');
    assert_contains('restore_version=' . $version['id'], $editor, 'the editor offers to open the draft');

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
    assert_not_contains('restore_version=' . $version['id'], $saved, 'saving clears the offer');

    delete_content_versions($id);
    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

t('the editor offers the component library as a dialog, not a drag palette', function () use ($base) {
    http_login($base);

    [$status, $editor] = http('GET', $base . '/admin/content/edit?type=page');

    assert_eq(200, $status);

    // The Add button and the dialog it opens.
    assert_contains('data-modal="component-picker"', $editor, 'the Add component button opens the dialog');
    assert_contains('class="component-add-zone"', $editor, 'the Add area is a wide target under the components');
    assert_contains('id="component-picker"', $editor);
    assert_contains('role="dialog"', $editor);
    assert_contains('aria-labelledby="component-picker-title"', $editor);

    // Tiles carry what the old palette could not: what it is for, and what it
    // looks like. Both come from the component and the previews folder.
    assert_contains('data-component-type="hero-section"', $editor);
    assert_contains('The opening band: heading, text, buttons and an image.', $editor, 'the tile shows the description');
    assert_contains('theme/assets/previews/hero-section.svg', $editor, 'the tile shows the preview');

    // The name-only list is gone.
    assert_not_contains('draggable-component', $editor);

    // The icon browser ships with the editor, one tile per icon the theme has.
    assert_contains('id="icon-picker"', $editor, 'the icon browser is in the page');
    assert_contains('data-icon="collection"', $editor, 'with the theme\'s icons as tiles');
    assert_contains('data-icon-picker', $editor, 'and the field that stores the choice');
});

t('an autosave draft can be dismissed from the editor', function () use ($base) {
    http_login($base);

    $id = http_seed_content('editor-dismiss', 'published', time());

    db()->prepare("UPDATE content SET updated_at = :time WHERE id = :id")
        ->execute(['time' => time() - 120, 'id' => $id]);

    $token = http_csrf_token($base, '/admin/content/edit?id=' . $id . '&type=page');

    http('POST', $base . '/admin/content/save', true, [
        '_token'   => $token,
        'autosave' => '1',
        'id'       => $id,
        'type'     => 'page',
        'title'    => 'Dismiss me',
        'slug'     => 'editor-dismiss',
        'status'   => 'published',
    ]);

    $version = latest_content_autosave($id);
    assert_true($version !== null, 'a draft was stored');

    // Dismissing discards the draft instead of only hiding the notice.
    [$status] = http('POST', $base . '/admin/content/edit?id=' . $id . '&type=page', true, [
        '_token'     => $token,
        'action'     => 'discard_autosave',
        'version_id' => $version['id'],
    ]);

    assert_eq(302, $status, 'dismiss redirects back to the editor');
    assert_eq(null, latest_content_autosave($id), 'the draft is gone');

    [, $after] = http('GET', $base . '/admin/content/edit?id=' . $id . '&type=page');
    assert_not_contains('restore_version=' . $version['id'], $after, 'the offer is gone');

    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

t('restoring an autosave loads it into the editor without touching the page', function () use ($base) {
    http_login($base);

    $id = http_seed_content('editor-load-draft', 'published', time());

    db()->prepare("UPDATE content SET updated_at = :time WHERE id = :id")
        ->execute(['time' => time() - 120, 'id' => $id]);

    $token = http_csrf_token($base, '/admin/content/edit?id=' . $id . '&type=page');

    http('POST', $base . '/admin/content/save', true, [
        '_token'   => $token,
        'autosave' => '1',
        'id'       => $id,
        'type'     => 'page',
        'title'    => 'Half finished draft',
        'slug'     => 'editor-load-draft',
        'status'   => 'published',
    ]);

    $version = latest_content_autosave($id);
    assert_true($version !== null);

    // Opening the draft fills the editor form...
    [$status, $editor] = http('GET', $base . '/admin/content/edit?id=' . $id . '&type=page&restore_version=' . $version['id']);
    assert_eq(200, $status);
    assert_contains('value="Half finished draft"', $editor, 'the draft is shown in the form');

    // ...and nothing is written or published.
    assert_eq('Editor load draft', load_content_by_id($id)['title'], 'the live row is unchanged');

    delete_content_versions($id);
    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

t('the history restore for an autosave opens the editor instead of publishing', function () use ($base) {
    http_login($base);

    $id = http_seed_content('editor-history-load', 'published', time());

    db()->prepare("UPDATE content SET updated_at = :time WHERE id = :id")
        ->execute(['time' => time() - 120, 'id' => $id]);

    $token = http_csrf_token($base, '/admin/content/edit?id=' . $id . '&type=page');

    http('POST', $base . '/admin/content/save', true, [
        '_token'   => $token,
        'autosave' => '1',
        'id'       => $id,
        'type'     => 'page',
        'title'    => 'Never publish me',
        'slug'     => 'editor-history-load',
        'status'   => 'published',
    ]);

    $version = latest_content_autosave($id);
    assert_true($version !== null);

    [$status, , $headers] = http('POST', $base . '/admin/content/versions?type=page&id=' . $id, true, [
        '_token'     => $token,
        'version_id' => $version['id'],
    ]);

    assert_eq(302, $status);
    assert_contains('restore_version=' . $version['id'], $headers, 'it redirects into the editor');
    assert_eq('Editor history load', load_content_by_id($id)['title'], 'the live row is unchanged');

    delete_content_versions($id);
    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

t('a blocked publish is saved as a draft and returns to the editor', function () use ($base) {
    http_login($base);

    $id = seed_content([
        'type'         => 'page',
        'slug'         => 'blocked-publish',
        'title'        => 'Blocked publish',
        'status'       => 'draft',
        'published_at' => null,
    ]);

    $token = http_csrf_token($base, '/admin/content/edit?id=' . $id . '&type=page');

    [$status] = http('POST', $base . '/admin/content/save', true, [
        '_token'     => $token,
        'id'         => $id,
        'type'       => 'page',
        'title'      => 'Blocked publish',
        'slug'       => 'blocked-publish',
        'status'     => 'published',
        'components' => [
            ['type' => 'hero-section', 'props' => ['title' => '', 'subtitle' => 'Sub']],
        ],
    ]);

    assert_eq(302, $status, 'the save redirects back to the editor');

    $row = db()->prepare("SELECT status, published_at FROM content WHERE id = :id");
    $row->execute(['id' => $id]);
    $saved = $row->fetch(PDO::FETCH_ASSOC);

    assert_eq('draft', $saved['status'], 'a blocked publish does not go live');
    assert_eq(null, $saved['published_at']);

    [, $editor] = http('GET', $base . '/admin/content/edit?id=' . $id . '&type=page');
    assert_contains('checklist-item', $editor, 'the checklist is shown in the editor');

    delete_content_versions($id);
    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

t('bulk publish skips items that are missing required fields', function () use ($base) {
    http_login($base);

    $complete = seed_content([
        'type' => 'page', 'slug' => 'bulk-complete', 'title' => 'Complete', 'status' => 'draft',
        'published_at' => null,
        'body' => [['type' => 'hero-section', 'props' => ['title' => 'T', 'subtitle' => 'S'], 'children' => []]],
    ]);

    $incomplete = seed_content([
        'type' => 'page', 'slug' => 'bulk-incomplete', 'title' => 'Incomplete', 'status' => 'draft',
        'published_at' => null,
        'body' => [['type' => 'hero-section', 'props' => ['title' => '', 'subtitle' => 'S'], 'children' => []]],
    ]);

    [$status] = http('POST', $base . '/admin/content/bulk', true, [
        '_token'      => http_csrf_token($base),
        'bulk_action' => 'publish',
        'type'        => 'page',
        'ids'         => [$complete, $incomplete],
    ]);

    assert_eq(302, $status);
    assert_eq('published', http_content_status($complete), 'the complete item publishes');
    assert_eq('draft', http_content_status($incomplete), 'the incomplete item is skipped');

    delete_content_versions($complete);
    delete_content_versions($incomplete);
    db()->prepare("DELETE FROM content WHERE id IN (?, ?)")->execute([$complete, $incomplete]);
});

t('the appearance settings reach the rendered page', function () use ($base) {
    $id = seed_content([
        'type'         => 'page',
        'slug'         => 'appearance-check',
        'title'        => 'Appearance check',
        'status'       => 'published',
        'published_at' => time(),
        'meta'         => '{}',
        'body'         => '[]',
    ]);

    try {
        save_settings([
            'favicon'          => 'https://example.com/icon.png',
            'site_description' => 'A description from Settings.',
            'logo'             => 'https://example.com/logo.png',
            'custom_css'       => '.from-settings { color: red; }',
        ]);

        test_clear_cache_files();
        [$status, $body] = http('GET', $base . '/appearance-check/', false);

        assert_eq(200, $status);
        assert_contains("<link rel='icon' href='https://example.com/icon.png'>", $body, 'the favicon setting wins');
        assert_contains("name='description' content='A description from Settings.'", $body, 'the site description fills in');
        assert_contains('https://example.com/logo.png', $body, 'the logo renders in the header');
        assert_contains('.from-settings { color: red; }', $body, 'custom CSS is injected');
    } finally {
        save_settings([
            'favicon'          => '',
            'site_description' => '',
            'logo'             => '',
            'custom_css'       => '',
        ]);
        test_clear_cache_files();
        db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
    }
});

t('maintenance mode closes the public site but not the editor', function () use ($base) {
    try {
        save_settings(['maintenance_mode' => true, 'maintenance_message' => 'Back after lunch.']);
        test_clear_cache_files();

        // Visitors get a 503 with a retry hint, and nothing is cached.
        [$status, $body, $headers] = http('GET', $base . '/about/', false);

        assert_eq(503, $status, 'the public site is closed');
        assert_contains('Retry-After:', $headers);
        assert_contains('Back after lunch.', $body, 'the configured message is shown');
        assert_not_contains('Set-Cookie', $headers, 'visitors still get no session');
        assert_count(0, glob(STORAGE_PATH . '/cache/about*.html') ?: [], 'a 503 is never cached');

        // Warming the cache would recreate the files the 503 path avoids.
        assert_eq(0, warm_cache()['rendered'], 'cache warming is skipped while closed');

        // A signed-in editor keeps working, so the site can be finished.
        http_login($base);

        [$status] = http('GET', $base . '/about/');
        assert_eq(200, $status, 'a signed-in editor is not blocked');

        [$status] = http('GET', $base . '/admin/content?type=page');
        assert_eq(200, $status, 'the admin keeps working');

        // The dashboard reminds whoever is signed in that the site is closed.
        [, $dashboard] = http('GET', $base . '/admin/dashboard');
        assert_contains('notice-warning', $dashboard, 'the dashboard shows a maintenance reminder');

        // A token preview is signed in, so it gets through too.
        [$status, $preview] = http('GET', $base . '/about/?preview=' . http_preview_token());
        assert_eq(200, $status, 'a token preview is not blocked');
        assert_contains('cms-preview-bar', $preview);
    } finally {
        save_settings(['maintenance_mode' => false]);
        test_clear_cache_files();
    }
});

t('the dashboard offers only what the signed-in role can open', function () use ($base) {
    // Something is waiting, so the tile would render for a role that may read it.
    db()->prepare("
        INSERT INTO form_submissions (form_type, data, status, created_at, updated_at)
        VALUES ('contact', '{}', 'new', :now, :now)
    ")->execute(['now' => time()]);

    // An author writes content but cannot open the forms inbox.
    db()->prepare("
        INSERT INTO users (username, email, password_hash, role, created_at)
        VALUES ('author-dashboard', 'author-dashboard@example.com', :hash, 'author', :now)
    ")->execute(['hash' => password_hash('secret-password', PASSWORD_DEFAULT), 'now' => time()]);

    // The tile, as opposed to the sidebar's per-form links.
    $inboxTile = 'href="' . url('admin/messages') . '"';

    http_login($base, 'author-dashboard', 'secret-password');
    [$status, $authorDashboard] = http('GET', $base . '/admin/dashboard');
    assert_eq(200, $status, 'the dashboard still opens for an author');
    assert_not_contains($inboxTile, $authorDashboard, 'an author is not offered an inbox that 403s');

    // The sidebar mirrors the page guard, so it lists nothing that 403s.
    $gatedLinks = [
        'admin/messages/?form='                 => 'the forms inbox',
        'href="' . url('admin/media') . '"'     => 'media',
        'href="' . url('admin/settings') . '"'  => 'settings',
        'href="' . url('admin/user') . '"'      => 'users',
        'href="' . url('admin/utilities') . '"' => 'utilities',
        'href="' . url('admin/category') . '"'  => 'categories',
        'href="' . url('admin/tag') . '"'       => 'tags',
        'href="' . url('admin/activity') . '"'  => 'the activity log',
    ];

    foreach ($gatedLinks as $needle => $label) {
        assert_not_contains($needle, $authorDashboard, "an author sidebar must not link to {$label}");
    }

    // …and keeps the pages an author may open.
    assert_contains('href="' . url('admin/content') . '?type=', $authorDashboard, 'an author keeps the content links');
    assert_contains('href="' . url('admin/analytics') . '"', $authorDashboard, 'and analytics');

    // The same page for an administrator offers all of it, so the checks above
    // are not passing merely because nothing renders.
    http_login($base);
    [, $adminDashboard] = http('GET', $base . '/admin/dashboard');
    assert_contains($inboxTile, $adminDashboard, 'an administrator is offered the inbox');
    assert_contains('admin/messages/?form=', $adminDashboard, 'and the sidebar forms links');
    assert_contains('href="' . url('admin/settings') . '"', $adminDashboard, 'and settings');
    assert_contains('href="' . url('admin/media') . '"', $adminDashboard, 'and media');
    assert_contains('href="' . url('admin/user') . '"', $adminDashboard, 'and users');
});

t('a content package exports through Utilities', function () use ($base) {
    http_login($base);

    // The dialog is two download buttons and nothing else: no checkboxes, no zip.
    [$status, $dialog] = http('GET', $base . '/admin/utilities', true);

    assert_eq(200, $status);
    assert_contains('name="section" value="content"', $dialog, 'one button exports the content');
    assert_contains('name="section" value="settings"', $dialog, 'and one exports the settings');

    $token = http_csrf_token($base, '/admin/utilities');

    [$status, $body, $headers] = http('POST', $base . '/admin/utilities', true, [
        'utility_action' => 'export_package',
        '_token'         => $token,
        'section'        => 'content',
    ]);

    assert_eq(200, $status);
    assert_contains('Content-Disposition: attachment; filename="content.json"', $headers, 'the download is named for the format');

    $document = json_decode($body, true);
    assert_eq(1, $document['format'] ?? null, 'it is a versioned package');
    // Earlier tests add content of their own, so the demo is a floor, not the
    // exact total.
    assert_true(count($document['content'] ?? []) >= demo_content_count(), 'every content item travels');
    assert_contains('"path": "home"', $body, 'the demo pages are in there');
    assert_not_contains('"settings"', $body, 'and settings stay out of a content-only export');

    [$status, $settings, $settingsHeaders] = http('POST', $base . '/admin/utilities', true, [
        'utility_action' => 'export_package',
        '_token'         => $token,
        'section'        => 'settings',
    ]);

    assert_eq(200, $status);
    assert_contains('Content-Disposition: attachment; filename="settings.json"', $settingsHeaders);
    assert_contains('"site_title"', $settings);
    assert_contains('"homepage": "page:home"', $settings, 'the homepage travels by slug, not id');
    assert_not_contains('site_url', $settings, 'environment settings never travel');

    // A request that names neither document is refused rather than guessing.
    [$status] = http('POST', $base . '/admin/utilities', true, [
        'utility_action' => 'export_package',
        '_token'         => $token,
    ]);

    assert_eq(302, $status, 'an unnamed export lands back on the page');
});

t('the theme demo imports through the Utilities preview', function () use ($base) {
    http_login($base);

    // The dialog has to name its source before anything is previewed, because
    // uploading files replaces the demo rather than adding to it.
    [$status, $dialog] = http('GET', $base . '/admin/utilities', true);

    assert_eq(200, $status);
    assert_contains('id="package-import-source"', $dialog, 'the source is shown up front');
    assert_contains(
        'data-demo="' . e(admin_trans('utilities_import_source_demo_auto')) . '"',
        $dialog,
        'and the demo is the default source'
    );
    assert_contains(
        'data-files="' . e(admin_trans('utilities_import_source_files')) . '"',
        $dialog,
        'while chosen files are stated to replace it'
    );

    $token = http_csrf_token($base, '/admin/utilities');

    // Preview: the report renders on the page rather than redirecting.
    [$status, $page] = http('POST', $base . '/admin/utilities', true, [
        'utility_action' => 'import_preview',
        '_token'         => $token,
        'sections'       => ['content', 'settings'],
    ]);

    assert_eq(200, $status);
    // The report is admin markup, so compare decoded text. Every translation is
    // also embedded in the page as JSON, so the apply step is asserted by its
    // own markup rather than by its label.
    $text = html_entity_decode($page, ENT_QUOTES);
    assert_contains(admin_trans('utilities_import_source_demo'), $text, 'the source is named');
    assert_contains('Replaces ', $text, 'and the plan is spelled out');
    assert_contains('name="token"', $page, 'the apply step is offered');
    // The apply form repeats the sections, so it imports what was previewed.
    assert_contains('type="hidden" name="sections[]" value="content"', $page, 'the content section is carried over');
    assert_contains('type="hidden" name="sections[]" value="settings"', $page, 'and so is settings');

    // Settings are imported with content, so the site title is restored.
    db()->prepare("UPDATE settings SET value = 'Changed By Hand' WHERE key = 'site_title'")->execute();

    [$status] = http('POST', $base . '/admin/utilities', true, [
        'utility_action' => 'import_apply',
        '_token'         => $token,
        'sections'       => ['content', 'settings'],
        'token'          => '',
    ]);

    assert_eq(302, $status, 'applying lands back on the page with a toast');
    assert_eq('Awesome site', get_setting('site_title'), 'the package settings were applied');
    assert_eq(demo_content_count(), (int) db()->query("SELECT COUNT(*) FROM content")->fetchColumn(), 'and the content replaced');
});

t('an uploaded package is stashed, previewed and applied', function () use ($base) {
    http_login($base);

    // A one-page package, so the result is unmistakable.
    $upload = test_tmp_root() . '/package-upload.json';
    file_put_contents($upload, json_encode([
        'format'  => 1,
        'content' => [[
            'type'   => 'page',
            'path'   => 'from-the-upload',
            'title'  => 'From The Upload',
            'status' => 'published',
            'meta'   => ['description' => 'Uploaded through the Utilities page.'],
            'body'   => [],
        ]],
        'menus' => [],
    ], JSON_UNESCAPED_SLASHES));

    $token = http_csrf_token($base, '/admin/utilities');

    [$status, $page] = http('POST', $base . '/admin/utilities', true, [
        'utility_action' => 'import_preview',
        '_token'         => $token,
        'sections'       => ['content'],
        // The [] is what makes PHP build an array in $_FILES.
        'files[]'        => new CURLFile($upload, 'application/json', 'content.json'),
    ]);

    assert_eq(200, $status);
    $text = html_entity_decode($page, ENT_QUOTES);
    assert_contains(admin_trans('utilities_import_source_files', ['count' => 1]), $text, 'the upload is the source');

    if (!preg_match('/name="token" value="([a-f0-9]{24})"/', $page, $matches)) {
        throw new RuntimeException('the preview did not offer a token to apply');
    }

    assert_true((bool) glob(STORAGE_PATH . '/imports/' . $matches[1] . '/*.json'), 'the upload waits in storage/imports');

    [$status] = http('POST', $base . '/admin/utilities', true, [
        'utility_action' => 'import_apply',
        '_token'         => $token,
        'sections'       => ['content'],
        'token'          => $matches[1],
    ]);

    assert_eq(302, $status);

    $slugs = db()->query("SELECT slug FROM content ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    assert_eq(['from-the-upload'], $slugs, 'the package replaced the content');
    assert_false(is_dir(STORAGE_PATH . '/imports/' . $matches[1]), 'and the stash was cleared');

    @unlink($upload);
});

t('a package this theme cannot render is refused in the preview', function () use ($base) {
    http_login($base);

    $upload = test_tmp_root() . '/package-bad.json';
    file_put_contents($upload, json_encode([
        'format'  => 1,
        'content' => [[
            'type'   => 'event',
            'path'   => 'launch-night',
            'title'  => 'Launch night',
            'status' => 'published',
            'meta'   => [],
            'body'   => [],
        ]],
    ]));

    $token = http_csrf_token($base, '/admin/utilities');

    [$status, $page] = http('POST', $base . '/admin/utilities', true, [
        'utility_action' => 'import_preview',
        '_token'         => $token,
        'sections'       => ['content'],
        'files[]'        => new CURLFile($upload, 'application/json', 'content.json'),
    ]);

    assert_eq(200, $status);
    $text = html_entity_decode($page, ENT_QUOTES);
    assert_contains(admin_trans('utilities_import_refused'), $text, 'the report refuses the import');
    assert_contains("Unknown content type 'event'", $text, 'and names what it does not have');
    assert_not_contains('name="token"', $page, 'so there is no apply step');
    assert_eq([], glob(STORAGE_PATH . '/imports/*/*.json') ?: [], 'and nothing is left stashed');

    @unlink($upload);
});

t('the history page diffs two versions and refuses another item\'s', function () use ($base) {
    http_login($base);

    $id = http_seed_content('history-diff-page', 'published', time());

    $snapshot = static fn(string $hero): array => [
        'title'        => 'Diff page',
        'status'       => 'published',
        'layout'       => null,
        'header'       => null,
        'footer'       => null,
        'meta'         => [],
        'body'         => [['type' => 'hero-section', 'props' => ['title' => $hero], 'children' => []]],
        'published_at' => null,
        'scheduled_at' => null,
    ];

    save_content_version($id, $snapshot('Old hero'), ['reason' => 'save', 'live' => false]);
    $older = list_content_versions($id)[0];

    save_content_version($id, $snapshot('New hero'), ['reason' => 'save', 'live' => false]);
    $newer = list_content_versions($id)[0];

    [$status, $body] = http(
        'GET',
        $base . '/admin/content/versions?type=page&id=' . $id . '&from=' . $older['id'] . '&to=' . $newer['id'],
        true
    );

    assert_eq(200, $status);
    assert_contains('diff-del', $body, 'removed lines are marked');
    assert_contains('diff-add', $body, 'added lines are marked');
    assert_contains('diff-same', $body, 'unchanged lines are shown muted');
    assert_contains('body[0] › title: Old hero', $body, 'the old value is shown');
    assert_contains('body[0] › title: New hero', $body, 'the new value is shown');
    assert_contains('name="from"', $body, 'the compare form lists both sides');

    // A version id belonging to another item is ignored, not resolved.
    $otherId = http_seed_content('history-diff-other', 'published', time());
    save_content_version($otherId, $snapshot('Other hero'), ['reason' => 'save', 'live' => false]);
    $otherVersion = list_content_versions($otherId)[0];

    [$status, $body] = http(
        'GET',
        $base . '/admin/content/versions?type=page&id=' . $id . '&from=' . $otherVersion['id'],
        true
    );

    assert_eq(200, $status);
    assert_not_contains('diff-del', $body, 'another item\'s version opens no diff');
    assert_not_contains('Other hero', $body, 'and its content never appears');

    delete_content_versions($id);
    delete_content_versions($otherId);
    db()->prepare('DELETE FROM content WHERE id = :id')->execute(['id' => $id]);
    db()->prepare('DELETE FROM content WHERE id = :id')->execute(['id' => $otherId]);
});

t('the utilities scans report orphaned media and dead links', function () use ($base) {
    http_login($base);

    $orphan = '2026/04/http0001';
    @mkdir(STORAGE_PATH . '/media/' . $orphan, 0777, true);
    file_put_contents(STORAGE_PATH . '/media/' . $orphan . '/x.jpg', 'xx');

    [$status, $body] = http('GET', $base . '/admin/utilities?scan=media', true);

    assert_eq(200, $status);
    assert_contains($orphan, $body, 'the orphan folder is listed');
    assert_contains('value="clean_media"', $body, 'and the repair is offered');

    $id = http_seed_content('http-broken-link', 'published', time());
    db()->prepare('UPDATE content SET body = :body WHERE id = :id')->execute([
        'body' => json_encode([['type' => 'cta-section', 'props' => ['title' => 'Go', 'text' => 't', 'url' => '/http-gone/', 'linktext' => 'Go'], 'children' => []]]),
        'id'   => $id,
    ]);

    [$status, $body] = http('GET', $base . '/admin/utilities?scan=links', true);

    assert_eq(200, $status);
    assert_contains('/http-gone/', $body, 'the dead link is listed');
    assert_contains('Http broken link', $body, 'with the content it lives in');

    // The repair removes the orphan folder and nothing else.
    $token = http_csrf_token($base, '/admin/utilities');
    http('POST', $base . '/admin/utilities', true, ['_token' => $token, 'utility_action' => 'clean_media']);

    assert_false(is_dir(STORAGE_PATH . '/media/' . $orphan), 'the orphan folder is gone');

    db()->prepare('DELETE FROM content WHERE id = :id')->execute(['id' => $id]);
});

// ---------------------------------------------------------------------------
// Shut the server down
// ---------------------------------------------------------------------------
proc_terminate($server);
proc_close($server);

exit(test_summary());

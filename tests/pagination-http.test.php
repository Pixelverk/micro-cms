<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pagination end to end
|--------------------------------------------------------------------------
|
| The parts that only exist through a real request: the listing component
| renders one page, the pager appears, `?page=2` is never cached (the page
| number is not part of the cache key), and the head carries rel=prev/next
| with a self-canonical.
|
| Re-executes itself with CMS_TEST_PAGINATION=1 so that only the child starts
| a server, the same arrangement http.test.php uses.
|
*/

if (getenv('CMS_TEST_PAGINATION') !== '1') {
    $child = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);

    $output = [];
    $exitCode = 0;
    exec('CMS_TEST_PAGINATION=1 ' . $child . ' 2>&1', $output, $exitCode);

    echo implode("\n", $output), "\n";
    exit($exitCode);
}

require __DIR__ . '/bootstrap.php';

if (!extension_loaded('curl')) {
    echo "FAIL pagination-http suite — the curl extension is required\n";
    exit(1);
}

$port      = 8700 + random_int(0, 300);
$base      = "http://127.0.0.1:{$port}";
$cookieJar = test_tmp_root() . '/cookies.txt';
$cacheDir  = STORAGE_PATH . '/cache';

test_fresh_database();

// A cold cache: earlier suites may have left cached pages behind.
foreach (glob($cacheDir . '/*.html') ?: [] as $file) {
    @unlink($file);
}

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', test_tmp_root() . '/pagination-http-server.log', 'a'], 2 => ['file', test_tmp_root() . '/pagination-http-server.log', 'a']],
    $pipes,
    CMS_PATH,
    ['CMS_CONFIG_FILE' => CMS_PATH . '/tests/config.server.php'] + $_ENV
);

if (!is_resource($server)) {
    echo "FAIL pagination-http suite — could not start the test server\n";
    exit(1);
}

function pag_http(string $method, string $url, bool $useCookies = false, array $post = []): array
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

function pag_login(string $base): void
{
    global $cookieJar;

    file_put_contents($cookieJar, '');

    [, $loginPage] = pag_http('GET', $base . '/admin/login');

    if (!preg_match('/name="_token" value="([^"]+)"/', $loginPage, $matches)) {
        throw new RuntimeException('login page did not contain a CSRF token');
    }

    [$status] = pag_http('POST', $base . '/admin/login', true, [
        'username' => 'demo',
        'password' => 'demo',
        '_token'   => $matches[1],
    ]);

    if ($status !== 302) {
        throw new RuntimeException("login failed with status {$status}");
    }
}

function pag_csrf_token(string $base, string $path): string
{
    [, $page] = pag_http('GET', $base . $path);

    if (!preg_match('/name="_token" value="([^"]+)"/', $page, $matches)) {
        throw new RuntimeException("no CSRF token on {$path}");
    }

    return $matches[1];
}

/**
 * Titles of the items the listing component rendered, in order.
 *
 * @return list<string>
 */
function pag_listed_titles(string $body): array
{
    preg_match_all('#<li class="list-section-item">\s*<h3><a[^>]*>([^<]+)</a>#', $body, $matches);

    return array_map('trim', $matches[1]);
}

// Wait for the server.
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
    echo "FAIL pagination-http suite — server never became ready\n";
    exit(1);
}

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
| A page that lists blog posts, four per page, and 10 posts to page through.
| Written directly so the suite does not depend on the admin editor.
*/

function pag_seed_listing_page(string $slug, int $perPage, string $type = 'blog_post'): int
{
    return seed_content([
        'type'   => 'page',
        'slug'   => $slug,
        'title'  => 'Listing',
        'status' => 'published',
        'body'   => [[
            'type'     => 'blog-list-section',
            'props'    => ['title' => 'All posts', 'content_type' => $type, 'per_page' => $perPage, 'show_date' => true, 'show_excerpt' => true],
            'children' => [],
        ]],
        'published_at' => time(),
    ]);
}

function pag_seed_posts(int $count): void
{
    // Start from no posts, so the expected page count is exact rather than
    // "however many the demo database happens to ship with".
    db()->exec("DELETE FROM content WHERE type = 'blog_post'");

    $now = time();

    foreach (range(1, $count) as $i) {
        seed_content([
            'type'         => 'blog_post',
            'slug'         => 'pag-post-' . $i,
            'title'        => 'Pag Post ' . $i,
            'status'       => 'published',
            // Descending dates: Pag Post 1 is newest, so page 1 starts there.
            'published_at' => $now - $i,
        ]);
    }
}

t('a listing renders one page of items and a pager', function () use ($base, $cacheDir) {
    pag_seed_listing_page('pag-listing', 4);
    pag_seed_posts(10);

    [$status, $body] = pag_http('GET', $base . '/pag-listing/');

    assert_eq(200, $status);
    assert_contains('All posts', $body, 'the section title renders');

    $titles = pag_listed_titles($body);
    assert_count(4, $titles, 'only the first page of items renders');
    assert_eq('Pag Post 1', $titles[0], 'newest first');
    assert_contains('Page 1 of 3', $body, 'the pager reports the page count');
    assert_contains('Next', $body, 'and offers the next page');

    // The first page is cacheable and gets written.
    assert_true(is_file($cacheDir . '/pag-listing.html'), 'page 1 is cached');
});

t('?page=2 shows the next items, not page 1 again', function () use ($base) {
    [$status, $body] = pag_http('GET', $base . '/pag-listing/?page=2');

    assert_eq(200, $status);

    $titles = pag_listed_titles($body);
    assert_count(4, $titles, 'a full second page');
    assert_eq('Pag Post 5', $titles[0], 'continues where page 1 stopped');
    assert_contains('Page 2 of 3', $body);
    assert_contains('Previous', $body, 'page 2 offers the previous page');
});

t('the last page holds the remainder and offers no next link', function () use ($base) {
    [, $body] = pag_http('GET', $base . '/pag-listing/?page=3');

    $titles = pag_listed_titles($body);
    assert_count(2, $titles, '10 items over 4 per page leaves 2');
    assert_eq('Pag Post 9', $titles[0]);
    assert_contains('Page 3 of 3', $body);
    assert_not_contains('>Next', $body, 'there is no next page');
});

t('a paged request is never cached and never served from the cache', function () use ($base, $cacheDir) {
    @unlink($cacheDir . '/pag-listing.html');

    [, $page2] = pag_http('GET', $base . '/pag-listing/?page=2');

    assert_false(is_file($cacheDir . '/pag-listing.html'), 'page 2 must not write the path cache entry');
    assert_eq('Pag Post 5', pag_listed_titles($page2)[0], 'page 2 rendered its own items');

    // Warm page 1, then ask for page 2 again: it must not get page 1's HTML.
    pag_http('GET', $base . '/pag-listing/');
    assert_true(is_file($cacheDir . '/pag-listing.html'), 'page 1 is cached again');

    [, $page2Again] = pag_http('GET', $base . '/pag-listing/?page=2');
    assert_eq('Pag Post 5', pag_listed_titles($page2Again)[0], 'page 2 is rendered live, not from the path cache');
});

t('the head carries rel=next on page 1 and rel=prev on page 2', function () use ($base) {
    [, $page1] = pag_http('GET', $base . '/pag-listing/');
    assert_contains("rel='next'", $page1, 'page 1 links forward');
    assert_contains('/pag-listing?page=2', $page1);
    assert_not_contains("rel='prev'", $page1, 'page 1 has nothing before it');

    [, $page2] = pag_http('GET', $base . '/pag-listing/?page=2');
    assert_contains("rel='prev'", $page2, 'page 2 links back');
    assert_contains("rel='next'", $page2, 'and forward');
});

t('a paged listing canonicalises to itself', function () use ($base) {
    [, $page2] = pag_http('GET', $base . '/pag-listing/?page=2');

    assert_contains("rel='canonical'", $page2);
    assert_contains('?page=2', $page2, 'page 2 is its own canonical, not page 1');
});

t('an out-of-range page renders empty rather than erroring', function () use ($base) {
    [$status, $body] = pag_http('GET', $base . '/pag-listing/?page=99');

    assert_eq(200, $status, 'no 404 and no redirect');
    assert_count(0, pag_listed_titles($body), 'nothing listed');
});

t('a listing component can page a different content type', function () use ($base) {
    pag_seed_listing_page('pag-portfolio', 1, 'portfolio_item');

    [, $body] = pag_http('GET', $base . '/pag-portfolio/');

    // The seeded demo has two portfolio items, one per page.
    assert_count(1, pag_listed_titles($body), 'one item per page');
    assert_contains('Page 1 of 2', $body, 'the type is listed independently of blog posts');
    assert_contains('/portfolio/', $body, 'items link through the type URL prefix');
});

t('a listing for an undeclared type renders nothing', function () use ($base) {
    pag_seed_listing_page('pag-unknown', 4, 'secret_type');

    [$status, $body] = pag_http('GET', $base . '/pag-unknown/');

    assert_eq(200, $status);
    // The component refuses an undeclared type, so it renders no item list at
    // all. Check the markup, not the class name, which also appears in the
    // component's inlined CSS.
    assert_count(0, pag_listed_titles($body));
    assert_not_contains('<ul class="list-section-items">', $body, 'nothing is listed for an undeclared type');
});

t('a taxonomy archive pages its items', function () use ($base) {
    // One category holding more posts than fit on a single archive page.
    // Seeded here rather than reused from an earlier test: those fixtures are
    // sized for the component listing and would leave only one archive page.
    $now  = time();
    $want = taxonomy_per_page() + 5;

    db()->exec("DELETE FROM content WHERE type = 'blog_post'");

    foreach (range(1, $want) as $i) {
        seed_content([
            'type'         => 'blog_post',
            'slug'         => 'arch-post-' . $i,
            'title'        => 'Arch Post ' . $i,
            'status'       => 'published',
            'published_at' => $now - $i,
        ]);
    }

    db()->prepare("INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, created_at, updated_at)
                   VALUES ('category', 'blog_post', 'Pag Cat', 'pag-cat', :now, :now)")
        ->execute(['now' => $now]);
    $categoryId = (int) db()->lastInsertId();

    $link = db()->prepare("INSERT INTO taxonomy_term_relationships (content_type, content_id, taxonomy_id) VALUES ('blog_post', :id, :tax)");

    foreach (list_content_page('blog_post', 1, 500)['items'] as $item) {
        $link->execute(['id' => (int) $item['id'], 'tax' => $categoryId]);
    }

    // A taxonomy archive's first page is cached, so make sure this assertion
    // reads a freshly rendered page rather than one from an earlier check.
    foreach (glob(STORAGE_PATH . '/cache/*.html') ?: [] as $cached) {
        @unlink($cached);
    }


    [$status, $body] = pag_http('GET', $base . '/category/pag-cat/');
    assert_eq(200, $status);

    $perPage = taxonomy_per_page();
    $listed  = substr_count($body, '<li class="taxonomy-item">');
    assert_eq($perPage, $listed, 'the archive lists exactly one page of items');
    assert_contains('Page 1 of', $body, 'and renders a pager');

    [, $page2] = pag_http('GET', $base . '/category/pag-cat/?page=2');
    assert_contains('Page 2 of', $page2);
    assert_true(
        substr_count($page2, '<li class="taxonomy-item">') > 0,
        'the second archive page still lists items'
    );
});

proc_terminate($server);
proc_close($server);

exit(test_summary());

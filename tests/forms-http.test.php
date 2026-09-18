<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Form inbox end to end
|--------------------------------------------------------------------------
|
| The parts that only exist through a real request: the inbox renders with
| filters and paging, a status change actually persists, a tampered status is
| refused, and the CSV download returns a parseable attachment.
|
| Re-executes itself with CMS_TEST_FORMS=1, so only the child starts a server.
|
*/

if (getenv('CMS_TEST_FORMS') !== '1') {
    $child = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);

    $output = [];
    $exitCode = 0;
    exec('CMS_TEST_FORMS=1 ' . $child . ' 2>&1', $output, $exitCode);

    echo implode("\n", $output), "\n";
    exit($exitCode);
}

require __DIR__ . '/bootstrap.php';

if (!extension_loaded('curl')) {
    echo "FAIL forms-http suite — the curl extension is required\n";
    exit(1);
}

$port      = 9000 + random_int(0, 300);
$base      = "http://127.0.0.1:{$port}";
$cookieJar = test_tmp_root() . '/cookies.txt';

test_fresh_database();

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', test_tmp_root() . '/forms-http-server.log', 'a'], 2 => ['file', test_tmp_root() . '/forms-http-server.log', 'a']],
    $pipes,
    CMS_PATH,
    ['CMS_CONFIG_FILE' => CMS_PATH . '/tests/config.server.php'] + $_ENV
);

if (!is_resource($server)) {
    echo "FAIL forms-http suite — could not start the test server\n";
    exit(1);
}

function inbox_http(string $method, string $url, bool $useCookies = true, array $post = []): array
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

function inbox_login(string $base): void
{
    global $cookieJar;

    file_put_contents($cookieJar, '');

    [, $loginPage] = inbox_http('GET', $base . '/admin/login');

    if (!preg_match('/name="_token" value="([^"]+)"/', $loginPage, $matches)) {
        throw new RuntimeException('login page did not contain a CSRF token');
    }

    [$status] = inbox_http('POST', $base . '/admin/login', true, [
        'username' => 'demo',
        'password' => 'demo',
        '_token'   => $matches[1],
    ]);

    if ($status !== 302) {
        throw new RuntimeException("login failed with status {$status}");
    }
}

function inbox_csrf_token(string $base, string $path = '/admin/messages'): string
{
    [, $page] = inbox_http('GET', $base . $path);

    if (!preg_match('/name="_token" value="([^"]+)"/', $page, $matches)) {
        throw new RuntimeException("no CSRF token on {$path}");
    }

    return $matches[1];
}

/**
 * Insert a submission straight into the table.
 *
 * @param array<string, mixed> $data
 */
function inbox_seed(string $formType, array $data, string $status = 'new', ?int $createdAt = null): int
{
    $now = $createdAt ?? time();

    db()->prepare("
        INSERT INTO form_submissions (form_type, data, status, created_at, updated_at)
        VALUES (:form_type, :data, :status, :created_at, :updated_at)
    ")->execute([
        'form_type'  => $formType,
        'data'       => json_encode($data, JSON_THROW_ON_ERROR),
        'status'     => $status,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) db()->lastInsertId();
}

function inbox_status(int $id): string
{
    return (string) db()->query("SELECT status FROM form_submissions WHERE id = {$id}")->fetchColumn();
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
    echo "FAIL forms-http suite — server never became ready\n";
    exit(1);
}

t('the inbox renders submissions with a status control', function () use ($base) {
    inbox_login($base);

    $id = inbox_seed('contact', ['name' => 'Inbox Render', 'email' => 'render@example.com', 'message' => 'Hello']);

    [$status, $body] = inbox_http('GET', $base . '/admin/messages');

    assert_eq(200, $status);
    assert_contains('Inbox Render', $body, 'the row is listed');
    assert_contains('render@example.com', $body, 'and its details are available');
    assert_contains('status-new', $body, 'the status badge renders');
    assert_contains('name="row_id" value="' . $id . '"', $body, 'a per-row status control exists');
    assert_contains('name="row_status"', $body);

    // The status must save from the control itself: no submit button, and the
    // select carries its own change handler.
    assert_contains('onchange="this.form.submit()"', $body, 'choosing a status submits the row');
    assert_not_contains('js-row-status-save', $body, 'no submit button beside the select');
    assert_contains('messages-select-all', $body, 'and a bulk selector');
});

t('filters narrow the list by form and status', function () use ($base) {
    inbox_login($base);
    db()->exec('DELETE FROM form_submissions');

    inbox_seed('contact', ['name' => 'Wanted'], 'new');
    inbox_seed('contact', ['name' => 'Handled One'], 'handled');
    inbox_seed('newsletter', ['email' => 'news@example.com'], 'new');

    [, $contacts] = inbox_http('GET', $base . '/admin/messages?form=contact');
    assert_contains('Wanted', $contacts);
    assert_contains('Handled One', $contacts);
    assert_not_contains('news@example.com', $contacts, 'the other form type is filtered out');

    [, $handled] = inbox_http('GET', $base . '/admin/messages?status=handled');
    assert_contains('Handled One', $handled);
    assert_not_contains('Wanted', $handled, 'a status filter excludes other statuses');

    [, $combo] = inbox_http('GET', $base . '/admin/messages?form=contact&status=new');
    assert_contains('Wanted', $combo);
    assert_not_contains('Handled One', $combo);
});

t('a per-row status change persists', function () use ($base) {
    inbox_login($base);

    $id = inbox_seed('contact', ['name' => 'Change Me']);

    [$status] = inbox_http('POST', $base . '/admin/messages/update', true, [
        '_token'     => inbox_csrf_token($base),
        'row_id'     => $id,
        'row_status' => 'waiting',
    ]);

    assert_eq(302, $status, 'the endpoint redirects back to the inbox');
    assert_eq('waiting', inbox_status($id));
});

t('several rows can be changed at once', function () use ($base) {
    inbox_login($base);

    $a = inbox_seed('contact', ['name' => 'Bulk A']);
    $b = inbox_seed('contact', ['name' => 'Bulk B']);
    $c = inbox_seed('contact', ['name' => 'Bulk C']);

    inbox_http('POST', $base . '/admin/messages/update', true, [
        '_token' => inbox_csrf_token($base),
        'ids'    => [$a, $b],
        'action' => 'handled',
    ]);

    assert_eq('handled', inbox_status($a));
    assert_eq('handled', inbox_status($b));
    assert_eq('new', inbox_status($c), 'an unticked row is untouched');
});

t('a tampered status is refused', function () use ($base) {
    inbox_login($base);

    $id = inbox_seed('contact', ['name' => 'Tamper']);

    inbox_http('POST', $base . '/admin/messages/update', true, [
        '_token'     => inbox_csrf_token($base),
        'row_id'     => $id,
        'row_status' => 'not-a-status',
    ]);

    assert_eq('new', inbox_status($id), 'an unknown status is never written');
});

t('a status change without a token is refused', function () use ($base) {
    inbox_login($base);

    $id = inbox_seed('contact', ['name' => 'No Token']);

    [$status] = inbox_http('POST', $base . '/admin/messages/update', true, [
        'row_id'     => $id,
        'row_status' => 'handled',
    ]);

    assert_eq(302, $status, 'the CSRF gate bounces it');
    assert_eq('new', inbox_status($id), 'nothing changed');
});

t('a bulk change with no selection is refused', function () use ($base) {
    inbox_login($base);

    $id = inbox_seed('contact', ['name' => 'Unselected']);

    inbox_http('POST', $base . '/admin/messages/update', true, [
        '_token' => inbox_csrf_token($base),
        'ids'    => [],
        'action' => 'handled',
    ]);

    assert_eq('new', inbox_status($id));
});

t('the CSV export downloads the filtered submissions', function () use ($base) {
    inbox_login($base);
    db()->exec('DELETE FROM form_submissions');

    inbox_seed('contact', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'message' => 'First'], 'handled', time() - 100);
    inbox_seed('contact', ['name' => 'Grace Hopper', 'email' => 'grace@example.com', 'message' => 'Second, with, commas'], 'new', time() - 50);
    inbox_seed('newsletter', ['email' => 'news@example.com'], 'new', time() - 10);

    [$status, $body, $headers] = inbox_http('GET', $base . '/admin/messages?form=contact&export=csv&scoped=1');

    assert_eq(200, $status);
    assert_contains('Content-Type: text/csv', $headers, 'served as CSV');
    assert_contains('Content-Disposition: attachment', $headers, 'and as a download');

    $lines = array_values(array_filter(explode("\n", trim($body))));
    assert_eq(3, count($lines), 'a header row plus the two contacts');

    $header = str_getcsv($lines[0]);
    assert_contains('Submitted At', $lines[0]);
    assert_contains('Status', $lines[0]);
    assert_true(in_array('Name', $header, true), 'field names become columns');
    assert_true(in_array('Email', $header, true));
    assert_true(in_array('Message', $header, true));

    // Oldest first, and the newsletter submission is excluded by the filter.
    $rows = array_map('str_getcsv', array_slice($lines, 1));
    $nameColumn = array_search('Name', $header, true);

    assert_eq('Ada Lovelace', $rows[0][$nameColumn]);
    assert_eq('Grace Hopper', $rows[1][$nameColumn]);

    $statusColumn = array_search('Status', $header, true);
    assert_eq('Handled', $rows[0][$statusColumn], 'the status is exported as its label');
    assert_eq('New', $rows[1][$statusColumn]);

    // A value containing commas survives a round trip through the CSV.
    $messageColumn = array_search('Message', $header, true);
    assert_eq('Second, with, commas', $rows[1][$messageColumn]);
});

t('a CSV export with no matching rows still returns its header', function () use ($base) {
    inbox_login($base);

    [$status, $body] = inbox_http('GET', $base . '/admin/messages?status=spam&export=csv&scoped=1');

    assert_eq(200, $status);
    assert_contains('Submitted At', $body, 'the header row is always present');

    $lines = array_values(array_filter(explode("\n", trim($body))));
    assert_eq(1, count($lines), 'and nothing else');
});

t('the inbox pages a long list', function () use ($base) {
    inbox_login($base);
    db()->exec('DELETE FROM form_submissions');

    // The page shows 20 at a time.
    foreach (range(1, 25) as $i) {
        inbox_seed('contact', ['name' => 'Paged ' . $i], 'new', time() - $i);
    }

    [$status, $page1] = inbox_http('GET', $base . '/admin/messages');
    assert_eq(200, $status);
    assert_contains('Page 1 of 2', $page1);
    assert_contains('Paged 1', $page1, 'newest first');
    assert_not_contains('Paged 25', $page1, 'the twenty-first row is not on page 1');

    [, $page2] = inbox_http('GET', $base . '/admin/messages?page=2');
    assert_contains('Page 2 of 2', $page2);
    assert_contains('Paged 25', $page2);
});

t('the top-right export ignores the filters, the scoped one does not', function () use ($base) {
    inbox_login($base);
    db()->exec('DELETE FROM form_submissions');

    inbox_seed('contact', ['name' => 'Contact Row'], 'new', time() - 20);
    inbox_seed('newsletter', ['email' => 'news@example.com'], 'new', time() - 10);

    // The link in the page header exports everything.
    [$status, $all] = inbox_http('GET', $base . '/admin/messages?export=csv');
    assert_eq(200, $status);
    assert_contains('Contact Row', $all);
    assert_contains('news@example.com', $all);

    // Adding scoped=1 narrows it to the view.
    [, $scoped] = inbox_http('GET', $base . '/admin/messages?form=contact&export=csv&scoped=1');
    assert_contains('Contact Row', $scoped);
    assert_not_contains('news@example.com', $scoped, 'the scoped export respects the filter');
});

t('the delete action removes a submission', function () use ($base) {
    inbox_login($base);

    $keep   = inbox_seed('contact', ['name' => 'Keep Me']);
    $remove = inbox_seed('contact', ['name' => 'Remove Me']);

    [$status] = inbox_http('POST', $base . '/admin/messages/update', true, [
        '_token' => inbox_csrf_token($base),
        'ids'    => [$remove],
        'action' => 'delete',
    ]);

    assert_eq(302, $status, 'the endpoint redirects back to the inbox');

    $remaining = db()->query('SELECT id FROM form_submissions')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array((string) $keep, array_map('strval', $remaining), true), 'the other row survives');
    assert_false(in_array((string) $remove, array_map('strval', $remaining), true), 'the deleted row is gone');

    $logged = (int) db()->query("SELECT COUNT(*) FROM activity_log WHERE action = 'form.deleted'")->fetchColumn();
    assert_eq(1, $logged, 'the deletion is audited');
});

t('deleting with no selection is refused', function () use ($base) {
    inbox_login($base);
    db()->exec('DELETE FROM form_submissions');

    $id = inbox_seed('contact', ['name' => 'Not Selected']);

    [$status] = inbox_http('POST', $base . '/admin/messages/update', true, [
        '_token' => inbox_csrf_token($base),
        'ids'    => [],
        'action' => 'delete',
    ]);

    assert_eq(302, $status);
    assert_eq(1, (int) db()->query('SELECT COUNT(*) FROM form_submissions')->fetchColumn(), 'nothing was deleted');
    assert_eq('new', inbox_status($id));
});

t('the bulk export returns exactly the selected rows', function () use ($base) {
    inbox_login($base);
    db()->exec('DELETE FROM form_submissions');

    $first  = inbox_seed('contact', ['name' => 'Selected One'], 'new', time() - 30);
    $second = inbox_seed('contact', ['name' => 'Selected Two'], 'new', time() - 20);
    inbox_seed('contact', ['name' => 'Left Behind'], 'new', time() - 10);

    [$status, $body, $headers] = inbox_http('POST', $base . '/admin/messages/update', true, [
        '_token' => inbox_csrf_token($base),
        'ids'    => [$first, $second],
        'action' => 'export',
    ]);

    assert_eq(200, $status);
    assert_contains('Content-Disposition: attachment', $headers);
    assert_contains('Selected One', $body);
    assert_contains('Selected Two', $body);
    assert_not_contains('Left Behind', $body, 'an unticked row is not exported');

    $lines = array_values(array_filter(explode("\n", trim($body))));
    assert_eq(3, count($lines), 'a header plus the two selected rows');
});

t('an unknown bulk action changes nothing', function () use ($base) {
    inbox_login($base);
    db()->exec('DELETE FROM form_submissions');

    $id = inbox_seed('contact', ['name' => 'Untouched']);

    inbox_http('POST', $base . '/admin/messages/update', true, [
        '_token' => inbox_csrf_token($base),
        'ids'    => [$id],
        'action' => 'explode',
    ]);

    assert_eq('new', inbox_status($id));
    assert_eq(1, (int) db()->query('SELECT COUNT(*) FROM form_submissions')->fetchColumn());
});

t('an anonymous visitor cannot reach the inbox or its export', function () use ($base) {
    // No cookies: the request is anonymous.
    [$inboxStatus] = inbox_http('GET', $base . '/admin/messages', false);
    [$exportStatus] = inbox_http('GET', $base . '/admin/messages?export=csv', false);
    [$updateStatus] = inbox_http('POST', $base . '/admin/messages/update', false, [
        'row_id'     => 1,
        'row_status' => 'handled',
    ]);

    assert_eq(302, $inboxStatus, 'the inbox requires a session');
    assert_eq(302, $exportStatus, 'so does the export');
    assert_eq(302, $updateStatus, 'and so does a status change');
});

proc_terminate($server);

exit(test_summary());

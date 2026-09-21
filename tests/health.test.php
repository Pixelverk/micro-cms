<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Site health
|--------------------------------------------------------------------------
|
| health_checks() is the whole report as data, so it can be verified without
| rendering a page. These checks pin the shape, the required extensions and
| the read-only storage case that started this feature.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('health_checks() returns a well-formed report', function () {
    $checks = health_checks();

    assert_true(count($checks) > 0, 'there are checks');

    foreach ($checks as $check) {
        assert_true(isset($check['label'], $check['status'], $check['detail']), 'every row has label, status and detail');
        assert_true(in_array($check['status'], ['ok', 'warn', 'fail'], true), "{$check['label']} has a known status");
    }
});

t('the required extensions and writable storage are reported as ok', function () {
    $byLabel = [];

    foreach (health_checks() as $check) {
        $byLabel[$check['label']] = $check['status'];
    }

    assert_eq('ok', $byLabel['Extension: pdo_sqlite'] ?? null, 'pdo_sqlite is required');
    assert_eq('ok', $byLabel['Extension: imagick'] ?? null, 'imagick is required');
    assert_eq('ok', $byLabel['Database'] ?? null, 'the test database is writable');
    assert_eq('ok', $byLabel['storage/'] ?? null, 'the test storage is writable');
    assert_eq('ok', $byLabel['storage/cache/'] ?? null, 'the test cache is writable');
});

t('the theme manifest is reported as healthy', function () {
    $byLabel = [];

    foreach (health_checks() as $check) {
        $byLabel[$check['label']] = $check;
    }

    foreach (['Theme layouts', 'Theme components', 'Theme meta fields', 'Theme assets', 'Theme partials', 'Theme form fields'] as $label) {
        assert_true(isset($byLabel[$label]), "{$label} should be part of the report");
        assert_eq('ok', $byLabel[$label]['status'] ?? null, "{$label} should be ok for the shipped theme");
    }
});

t('a read-only storage directory is reported as a problem', function () {
    $directory = STORAGE_PATH . '/cache';
    $original  = fileperms($directory) & 0777;

    chmod($directory, 0555);

    // A privileged test user (root) ignores the mode.
    if (is_writable($directory)) {
        chmod($directory, $original);
        return;
    }

    try {
        $status = null;

        foreach (health_checks() as $check) {
            if ($check['label'] === 'storage/cache/') {
                $status = $check['status'];
            }
        }

        assert_eq('fail', $status, 'an unwritable cache directory is a failure');
    } finally {
        chmod($directory, $original);
    }
});

t('health_summary() counts each status', function () {
    $summary = health_summary([
        ['status' => 'ok'],
        ['status' => 'ok'],
        ['status' => 'warn'],
        ['status' => 'fail'],
    ]);

    assert_eq(['ok' => 2, 'warn' => 1, 'fail' => 1], $summary);
});

exit(test_summary());

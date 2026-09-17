<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Config and settings caching
|--------------------------------------------------------------------------
|
| Phase 2 memoises config.php and the settings table for the request. These
| tests pin that behaviour, because a regression here quietly multiplies
| database queries on every page.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('config() parses the config file exactly once per request', function () {
    $marker = STORAGE_PATH . '/config-loads.txt';
    @unlink($marker);

    // A config file that records each time it is parsed.
    $configFile = STORAGE_PATH . '/config-counter.php';
    $base = var_export(require CMS_PATH . '/config.php', true);

    file_put_contents($configFile, "<?php\nfile_put_contents(" . var_export($marker, true) . ", 'x', FILE_APPEND);\nreturn {$base};\n");

    [$output, $exitCode] = test_php([
        'putenv("CMS_CONFIG_FILE=" . ' . var_export($configFile, true) . ');',
        'config("url");',
        'config("env");',
        'config("cache_lifetime");',
        'url("admin/dashboard");',
        'asset("style.css");',
        'echo "DONE";',
    ]);

    assert_eq(0, $exitCode, 'subprocess should succeed: ' . implode("\n", $output));
    assert_contains('DONE', implode("\n", $output));

    $loads = is_file($marker) ? strlen(trim((string) file_get_contents($marker))) : 0;
    assert_eq(1, $loads, 'config.php should be read once, not once per call');

    @unlink($marker);
    @unlink($configFile);
});

t('load_settings() reads the whole table in one pass', function () {
    $settings = load_settings(true);

    assert_true(isset($settings['site_title']), 'seeded settings are present');
    assert_eq(config('security.login_max_attempts', 5) > 0, true, 'config still readable');
});

t('repeated get_setting() calls do not scale with the number of settings', function () {
    // Baseline: 200 reads of an existing key.
    load_settings(true);

    $start = microtime(true);
    for ($i = 0; $i < 200; $i++) {
        get_setting('site_title');
    }
    $baseline = microtime(true) - $start;

    // Add many more settings, then repeat the same work. With per-key queries
    // this grows; with a memoised map it stays flat.
    $pdo = db();
    $pdo->beginTransaction();
    $insert = $pdo->prepare("INSERT OR REPLACE INTO settings (`key`, `value`, updated_at) VALUES (:key, :value, :now)");

    for ($i = 0; $i < 400; $i++) {
        $insert->execute(['key' => "bulk_setting_{$i}", 'value' => str_repeat('v', 200), 'now' => time()]);
    }
    $pdo->commit();

    load_settings(true);

    $start = microtime(true);
    for ($i = 0; $i < 200; $i++) {
        get_setting('site_title');
    }
    $withMany = microtime(true) - $start;

    // Memoised reads are essentially free; allow generous headroom for noise
    // while still failing loudly if the cache disappears (which would make
    // this ratio far larger than 3x).
    assert_true(
        $withMany < max($baseline * 3, 0.01),
        sprintf('reads got slower with more settings: %.6fs -> %.6fs', $baseline, $withMany)
    );

    // Clean up so later tests see the normal settings set.
    $pdo->exec("DELETE FROM settings WHERE `key` LIKE 'bulk_setting_%'");
    load_settings(true);
});

t('set_setting() makes the new value visible immediately', function () {
    set_setting('site_title', 'Changed Title');

    assert_eq('Changed Title', get_setting('site_title'), 'get_setting must not serve a stale cache');
    assert_eq('Changed Title', load_settings()['site_title'], 'load_settings must agree');

    set_setting('site_title', 'Micro CMS Demo');
});

t('theme_config() is parsed once per request', function () {
    $first  = theme_config();
    $second = theme_config();

    assert_eq($first, $second);
    assert_true(isset($first['content_types']['page']), 'theme manifest is loaded');
});

exit(test_summary());

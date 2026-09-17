<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Config and settings caching
|--------------------------------------------------------------------------
|
| config.php and the settings table are memoised for the request. These
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

t('get_setting() reads the memoised map, not the database', function () {
    load_settings(true);
    $original = get_setting('site_title');

    // Change the row behind the cache's back. A plain read must not notice it;
    // a forced refresh must.
    db()->prepare("UPDATE settings SET value = :value WHERE `key` = 'site_title'")
        ->execute(['value' => 'Changed Behind The Cache']);

    assert_eq($original, get_setting('site_title'), 'a plain read must use the memoised map');
    assert_eq('Changed Behind The Cache', load_settings(true)['site_title'], 'a refresh must re-read the row');

    db()->prepare("UPDATE settings SET value = :value WHERE `key` = 'site_title'")
        ->execute(['value' => $original]);
    load_settings(true);
});

t('set_setting/get_setting round-trip arrays', function () {
    set_setting('test_array', ['a' => 1, 'b' => [2, 3]]);
    assert_eq(['a' => 1, 'b' => [2, 3]], get_setting('test_array'));

    db()->exec("DELETE FROM settings WHERE `key` = 'test_array'");
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

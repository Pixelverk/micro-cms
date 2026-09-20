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

t('setting_value_changed() ignores type-only differences', function () {
    // The settings form submits strings, but stored values keep their type
    // (booleans, integers, arrays). Only a real change should count.
    $unchanged = [
        [true, true], [true, '1'], [false, false], [false, ''], [false, '0'],
        [10, '10'], [0, '0'], [1.5, '1.5'],
        ['en', 'en'], ['', ''],
        [null, null], [null, ''],
        [[400, 800], [400, 800]],
        [['page' => ''], ['page' => '']],
    ];

    foreach ($unchanged as $pair) {
        assert_false(
            setting_value_changed($pair[0], $pair[1]),
            var_export($pair[0], true) . ' vs ' . var_export($pair[1], true) . ' should read as unchanged'
        );
    }
});

t('setting_value_changed() detects real changes', function () {
    $changed = [
        [true, false], [true, '0'], [false, true], [false, '1'],
        [10, '20'], [0, 1],
        ['en', 'sv'], ['site', 'Site'], ['', 'value'],
        [null, 'value'],
        [[400, 800], [400, 1200]],
        [['page' => ''], ['page' => 'pages']],
    ];

    foreach ($changed as $pair) {
        assert_true(
            setting_value_changed($pair[0], $pair[1]),
            var_export($pair[0], true) . ' vs ' . var_export($pair[1], true) . ' should read as a change'
        );
    }
});

// ---------------------------------------------------------------------------
// Appearance and locale settings
// ---------------------------------------------------------------------------

t('site_timezone() falls back to the shipped default', function () {
    set_setting('timezone', 'Europe/Stockholm');
    assert_eq('Europe/Stockholm', site_timezone());

    set_setting('timezone', 'America/New_York');
    assert_eq('America/New_York', site_timezone());

    // An invalid value never reaches DateTimeZone.
    set_setting('timezone', 'Not/AZone');
    assert_eq('Europe/Stockholm', site_timezone());

    set_setting('timezone', 'Europe/Stockholm');
});

t('format_date() uses the date format and timezone settings', function () {
    set_setting('date_format', 'Y-m-d');
    set_setting('timezone', 'UTC');

    $timestamp = gmmktime(9, 0, 0, 3, 1, 2026);

    assert_eq('2026-03-01', format_date($timestamp));
    assert_eq('01.03.2026', format_date($timestamp, 'd.m.Y'), 'an explicit format wins');
    assert_eq('', format_date(null), 'a missing date is empty');

    // The site timezone shifts the rendered date.
    $evening = gmmktime(23, 0, 0, 2, 28, 2026);
    assert_eq('2026-02-28', format_date($evening));

    set_setting('timezone', 'Pacific/Auckland');
    assert_eq('2026-03-01', format_date($evening), 'the same instant is the next day in Auckland');

    set_setting('timezone', 'Europe/Stockholm');
    set_setting('date_format', 'F j, Y');
});

t('image settings resolve media ids, URLs and theme filenames', function () {
    set_setting('logo', 'icon-512.png');
    assert_contains('theme/assets/img/icon-512.png', site_logo_url());

    // A filename the theme does not ship is not a logo; nothing renders.
    set_setting('logo', 'gone.png');
    assert_eq('', site_logo_url());

    set_setting('logo', 'https://cdn.test/logo.svg');
    assert_eq('https://cdn.test/logo.svg', site_logo_url());

    // A blank setting falls back to the theme manifest, then to nothing.
    set_setting('logo', '');
    assert_eq('', site_logo_url(), 'the demo theme declares no logo');

    assert_contains('theme/assets/favicon.ico', site_favicon_url(), 'the theme manifest favicon is the fallback');

    set_setting('favicon', 'icon-192.png');
    assert_contains('theme/assets/img/icon-192.png', site_favicon_url(), 'the setting wins over the manifest');

    set_setting('favicon', '');
});

t('validate_image_reference() accepts the documented shapes', function () {
    assert_true(validate_image_reference(''), 'blank is allowed by default');
    assert_true(validate_image_reference('42'), 'a media id');
    assert_true(validate_image_reference('https://example.com/logo.png'));
    assert_true(validate_image_reference('img/logo.svg'));
    assert_true(validate_image_reference('favicon.ico'));

    assert_false(validate_image_reference('logo.txt'));
    assert_false(validate_image_reference('javascript:alert(1)'));
    assert_false(validate_image_reference('', false), 'blank is refused when required');
});

exit(test_summary());

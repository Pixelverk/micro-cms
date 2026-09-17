<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Validation helpers
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('validate_slug() accepts router-safe slugs only', function () {
    assert_true(validate_slug('about-us'));
    assert_true(validate_slug('page2'));
    assert_false(validate_slug('About Us'), 'spaces are not allowed');
    assert_false(validate_slug('about/us'), 'slashes belong to the path, not the slug');
    assert_false(validate_slug(''), 'empty is invalid');
    assert_false(validate_slug('ünïcode'));
});

t('validate_url_prefix() allows nested-but-safe prefixes', function () {
    assert_true(validate_url_prefix(''), 'blank is allowed (root)');
    assert_true(validate_url_prefix('blog'));
    assert_true(validate_url_prefix('news/2026'));

    assert_false(validate_url_prefix('/blog'), 'no leading slash');
    assert_false(validate_url_prefix('blog/'), 'no trailing slash');
    assert_false(validate_url_prefix('Blog'), 'lowercase only');
    assert_false(validate_url_prefix('blog post'));
});

t('validate_email() honours allowEmpty', function () {
    assert_true(validate_email('a@example.com'));
    assert_false(validate_email('nope'));
    assert_false(validate_email(''), 'empty is invalid by default');
    assert_true(validate_email('', true), 'empty is allowed when optional');
});

t('validate_username() matches the documented shape', function () {
    assert_true(validate_username('demo'));
    assert_true(validate_username('jane.doe_1'));
    assert_false(validate_username('ab'), 'too short');
    assert_false(validate_username(str_repeat('a', 33)), 'too long');
    assert_false(validate_username('Jane Doe'));
});

t('validate_language_code() accepts xx and xx-YY', function () {
    assert_true(validate_language_code('en'));
    assert_true(validate_language_code('sv'));
    assert_true(validate_language_code('en-GB'));
    assert_false(validate_language_code('english'));
    assert_false(validate_language_code('e'));
});

t('validate_sizes_csv() normalises widths', function () {
    assert_eq([320, 640, 1280], validate_sizes_csv('640,320,1280'), 'sorted and de-duplicated');
    assert_eq([320], validate_sizes_csv('320,320'), 'duplicates collapse');
    assert_eq(null, validate_sizes_csv('abc'), 'non-numeric rejected');
    assert_eq(null, validate_sizes_csv('4'), 'below the minimum rejected');
    assert_eq(null, validate_sizes_csv('99999'), 'above the maximum rejected');
    assert_eq(null, validate_sizes_csv(''), 'empty rejected');
});

t('validate_int_range() and validate_enum() guard settings', function () {
    assert_true(validate_int_range('80', 1, 100));
    assert_false(validate_int_range('0', 1, 100));
    assert_false(validate_int_range('abc', 1, 100));

    assert_true(validate_enum('draft', content_statuses()));
    assert_false(validate_enum('deleted', content_statuses()));
});

t('validate_local_datetime() converts site time to a UTC timestamp', function () {
    $timestamp = validate_local_datetime('2026-03-01T09:30', 'Europe/Stockholm');

    assert_true($timestamp !== null, 'a valid value parses');

    $utc = (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
    assert_eq('2026-03-01 08:30', $utc, 'Stockholm is UTC+1 in March');

    assert_eq(null, validate_local_datetime('', 'Europe/Stockholm'));
    assert_eq(null, validate_local_datetime('not a date', 'Europe/Stockholm'));
});

t('validate_throw() reports JSON callers with 422', function () {
    [$output] = test_php([
        '$_SERVER["HTTP_ACCEPT"] = "application/json";',
        'validate_throw(["slug" => "Bad slug."], "settings");',
        'echo "REACHED";',
    ]);

    $text = implode("\n", $output);
    assert_not_contains('REACHED', $text, 'must exit');
    assert_contains('Validation failed', $text);
    assert_contains('Bad slug.', $text);
});

exit(test_summary());

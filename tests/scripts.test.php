<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Header and footer scripts
|--------------------------------------------------------------------------
|
| Settings may carry raw code that is written into every public page.
| These checks pin the injection points, that the code is emitted
| untouched, and that minification cannot rewrite it.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('header and footer scripts are injected inside head and body', function () {
    set_setting('header_scripts', '<script>window.HEAD = 1;</script>');
    set_setting('footer_scripts', '<script>window.FOOT = 1;</script>');

    $html = render_page(load_content_by_slug('about'))['body'];

    $headPosition = strpos($html, 'window.HEAD');
    $footPosition = strpos($html, 'window.FOOT');

    assert_true($headPosition !== false, 'the header snippet is rendered');
    assert_true($footPosition !== false, 'the footer snippet is rendered');
    assert_true($headPosition < strpos($html, '</head>'), 'the header snippet stays inside <head>');
    assert_true($footPosition < strpos($html, '</body>'), 'the footer snippet stays inside <body>');

    // Raw output: the markup is inserted, not escaped.
    assert_contains('<script>window.HEAD = 1;</script>', $html);

    set_setting('header_scripts', '');
    set_setting('footer_scripts', '');
});

t('empty snippets inject nothing', function () {
    set_setting('header_scripts', '');
    set_setting('footer_scripts', '');

    $html = render_page(load_content_by_slug('about'))['body'];

    assert_not_contains('window.HEAD', $html);
    assert_not_contains('window.FOOT', $html);
});

t('production minification does not rewrite injected code', function () {
    // A comment with significant whitespace: the minifier collapses it if the
    // snippet is present before minify_html() runs.
    $snippet = "<!--\n  keep this spacing\n-->";

    $configFile = test_tmp_root() . '/config-scripts-production.php';
    $config = require CMS_PATH . '/config.php';
    $config['env'] = 'production';
    file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");

    [$output, $exitCode] = test_php([
        'putenv("CMS_CONFIG_FILE=" . ' . var_export($configFile, true) . ');',
        'set_setting("header_scripts", ' . var_export($snippet, true) . ');',
        '$html = render_page(load_content_by_slug("about"))["body"];',
        'echo str_contains($html, ' . var_export($snippet, true) . ') ? "KEPT" : "ALTERED";',
    ]);

    assert_eq(0, $exitCode, 'subprocess should succeed: ' . implode("\n", $output));
    assert_contains('KEPT', implode("\n", $output), 'the snippet must survive minification intact');

    set_setting('header_scripts', '');
    @unlink($configFile);
});

exit(test_summary());

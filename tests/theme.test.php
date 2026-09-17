<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Theme asset ownership
|--------------------------------------------------------------------------
|
| Phase 4 split the theme's CSS into layers and moved component-specific
| rules into the components. These checks are source-level on purpose: they
| catch a rule drifting back into the shared layer, which is otherwise easy
| to miss because it still renders correctly.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

function theme_css(string $file): string
{
    return (string) file_get_contents(CMS_PATH . '/theme/assets/' . $file);
}

t('utilities.css carries the shared class layer', function () {
    $css = theme_css('utilities.css');

    foreach (['.container', '.row', '.col-lg-6', '.py-5', '.d-flex', '.card', '.btn', '.navbar', '.accordion-button'] as $selector) {
        assert_contains($selector, $css, "utilities.css should define {$selector}");
    }
});

t('style.css holds the theme surface, not the utility layer', function () {
    $css = theme_css('style.css');

    assert_contains(':root', $css, 'design tokens live here');
    assert_contains('--theme-primary', $css);
    assert_contains('box-sizing: border-box', $css, 'base reset stays here');

    // A utility leaking back in means the split is drifting.
    assert_not_contains('.container {', $css, 'grid belongs in utilities.css');
    assert_not_contains('.navbar {', $css, 'navbar belongs in utilities.css');
});

t('taxonomy archive CSS lives with the archive layouts', function () {
    assert_not_contains('taxonomy-archive-page', theme_css('style.css'), 'moved out of style.css');
    assert_not_contains('taxonomy-archive-page', theme_css('utilities.css'), 'not a shared utility');

    $partial = CMS_PATH . '/theme/partials/taxonomy-archive.css.php';
    assert_true(is_file($partial), 'the shared archive partial exists');
    assert_contains('taxonomy-archive-page', (string) file_get_contents($partial));

    // Both archive layouts must opt in, or one of them loses its styling.
    foreach (['taxonomy.php', 'blog-archive.php'] as $layout) {
        $source = (string) file_get_contents(CMS_PATH . '/theme/layouts/' . $layout);
        assert_contains('taxonomy-archive.css.php', $source, "{$layout} should include the archive CSS");
    }
});

t('component-specific rules are not in the shared layer', function () {
    $utilities = theme_css('utilities.css');

    assert_not_contains('.contact-form', $utilities, 'contact form styling belongs to contact-section');
    assert_not_contains('.bg-featured-blog', $utilities, 'blog hero styling belongs to blog-featured-section');

    // …and they are present in the component that owns them.
    assert_contains('.contact-form', (string) file_get_contents(CMS_PATH . '/theme/components/contact-section.php'));
    assert_contains('.bg-featured-blog', (string) file_get_contents(CMS_PATH . '/theme/components/blog-featured-section.php'));
});

t('every theme component follows the component contract', function () {
    $files = glob(CMS_PATH . '/theme/components/*.php') ?: [];
    assert_true(count($files) >= 20, 'expected the seeded component set');

    $withCss = 0;

    foreach ($files as $file) {
        $component = require $file;
        $name = basename($file);

        assert_true(is_array($component), "{$name} must return an array");
        assert_true(isset($component['schema']), "{$name} needs a schema");
        assert_true(is_callable($component['render'] ?? null), "{$name} needs a render function");

        // 'css' and 'js' are optional in core/render.php, but any component
        // that carries styling must declare it here rather than in a shared
        // stylesheet.
        if (!empty($component['css'])) {
            $withCss++;
        }
    }

    assert_true($withCss >= 1, 'at least one component should ship its own CSS');
});

t('no CDN references remain in the admin or theme', function () {
    $offenders = [];

    foreach ([CMS_PATH . '/admin', CMS_PATH . '/theme', CMS_PATH . '/core'] as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!in_array($file->getExtension(), ['php', 'js', 'css'], true)) {
                continue;
            }

            // Vendored libraries are shipped locally; their own licence
            // headers may mention upstream URLs, so skip the vendor dirs.
            if (str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('#(src|href)\s*=\s*["\'](https?:)?//#i', $contents)) {
                $offenders[] = str_replace(CMS_PATH . '/', '', $file->getPathname());
            }
        }
    }

    assert_count(0, $offenders, 'external asset references found: ' . implode(', ', $offenders));
});

t('the editor libraries are vendored locally', function () {
    foreach ([
        'admin/assets/vendor/quill/quill.js',
        'admin/assets/vendor/quill/quill.snow.css',
        'admin/assets/vendor/sortable/Sortable.min.js',
    ] as $path) {
        $full = CMS_PATH . '/' . $path;
        assert_true(is_file($full), "{$path} should exist");
        assert_true(filesize($full) > 1000, "{$path} looks empty");
    }

    $editor = (string) file_get_contents(CMS_PATH . '/admin/content/edit.php');
    assert_contains('admin/assets/vendor/quill/quill.js', $editor);
    assert_contains('admin/assets/vendor/sortable/Sortable.min.js', $editor);
});

t('collect_css() de-duplicates by key', function () {
    $collected = [];

    collect_css($collected, 'layout:x', '.a { color: red; }');
    collect_css($collected, 'layout:x', '.a { color: blue; }');
    collect_css($collected, 'layout:y', '.b { color: green; }');

    assert_count(2, $collected, 'the second call for the same key is ignored');
    assert_eq('.a { color: red; }', $collected[0]['content']);
});

exit(test_summary());

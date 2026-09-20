<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Theme asset ownership
|--------------------------------------------------------------------------
|
| The theme's CSS is split into layers, with component-specific rules kept
| in the components. These checks are source-level on purpose: they
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

    // about-feature-section's "Image Position: right" is these two classes, so
    // dropping them from the layer silently turns the option into a no-op.
    foreach (['.order-first', '.order-lg-last'] as $selector) {
        assert_contains($selector, $css, "utilities.css should define {$selector}");
    }

    // Menus mark the current page on the server; without this rule the markup
    // would carry the state and show nothing for it.
    assert_contains('.nav-link.active', $css, 'the active nav state has to be visible');
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

    assert_not_contains('.contact-form', $utilities, 'form styling belongs to the form partial');
    assert_not_contains('.bg-featured-blog', $utilities, 'blog hero styling belongs to blog-featured-section');

    // …and they are present where the markup that needs them lives: both
    // contact-section and blog-preview-section render the shared form partial.
    assert_contains('.theme-form .message', (string) file_get_contents(CMS_PATH . '/theme/partials/form.php'));
    assert_contains('.bg-featured-blog', (string) file_get_contents(CMS_PATH . '/theme/components/blog-featured-section.php'));
});

t('the about feature section puts its image on either side', function () {
    $component = require CMS_PATH . '/theme/components/about-feature-section.php';
    $js = [];
    $collected = [];

    $render = function (string $position) use ($component, &$js, &$collected): string {
        ob_start();
        $component['render']([
            'title'          => 'Section',
            'text'           => 'Text',
            'image'          => ':placeholder',
            'image_position' => $position,
        ], [], $js, $collected);

        return (string) ob_get_clean();
    };

    $right = $render('right');
    $left  = $render('left');

    assert_contains('order-first order-lg-last', $right, 'the right-hand variant flips the image column');
    assert_not_contains('order-lg-last', $left, 'the left-hand variant leaves the markup order alone');
});

t('every theme component follows the component contract', function () {
    $files = glob(CMS_PATH . '/theme/components/*.php') ?: [];
    assert_true(count($files) >= 20, 'expected the seeded component set');

    foreach ($files as $file) {
        $component = require $file;
        $name = basename($file);

        assert_true(is_array($component), "{$name} must return an array");
        assert_true(isset($component['schema']), "{$name} needs a schema");
        assert_true(is_callable($component['render'] ?? null), "{$name} needs a render function");
    }
});

t('every component the library offers is described and previewed', function () {
    $config  = theme_config();
    $headers = array_keys($config['headers'] ?? []);
    $footers = array_keys($config['footers'] ?? []);
    $offered = [];

    foreach ($config['content_types'] as $definition) {
        foreach (($definition['available_components'] ?? []) as $name) {
            // The layout owns the header and footer; they are never tiles.
            if (!in_array($name, $headers, true) && !in_array($name, $footers, true)) {
                $offered[$name] = true;
            }
        }
    }

    assert_true($offered !== [], 'the theme offers components to add');

    $incomplete = [];

    foreach (array_keys($offered) as $name) {
        if (trim((string) (content_component_definition($name)['description'] ?? '')) === '') {
            $incomplete[] = "{$name} has no description";
        }

        if (component_preview_url($name) === '') {
            $incomplete[] = "{$name} has no preview";
        }
    }

    assert_count(0, $incomplete, implode('; ', $incomplete));

    // The convention is a name, not a path: the picker builds the URL itself.
    assert_contains('theme/assets/previews/hero-section.svg', component_preview_url('hero-section'));
    assert_eq('', component_preview_url('not-a-component'), 'no preview file, no URL');
    assert_eq('', component_preview_url('../../config'), 'a path is not a component name');
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

t('theme images resolve through render_image(), not img() directly', function () {
    // A meta value (meta.thumbnail, gallery rows) can be a media id, which
    // img() would resolve as a theme asset and 404. render_image() picks
    // picture() for media ids and a bare <img> for filenames and URLs.
    $offenders = [];

    $files = array_merge(
        glob(CMS_PATH . '/theme/components/*.php') ?: [],
        glob(CMS_PATH . '/theme/layouts/*.php') ?: []
    );

    foreach ($files as $file) {
        if (str_contains((string) file_get_contents($file), 'img($')) {
            $offenders[] = str_replace(CMS_PATH . '/', '', $file);
        }
    }

    assert_count(0, $offenders, 'img() used directly in: ' . implode(', ', $offenders));
});

t('the content types that render a thumbnail expose it to the editor', function () {
    $types = theme_config()['content_types'] ?? [];

    foreach (['blog_post', 'portfolio_item'] as $type) {
        assert_true(isset($types[$type]['images']['thumbnail']), "{$type} declares a thumbnail field");
    }

    assert_true(!empty($types['portfolio_item']['images']['gallery']['multiple']), 'portfolio galleries are repeatable');
});

t('a cleared image prop falls back to the CMS placeholder', function () {
    $page = ['title' => 'Test', 'type' => 'page'];

    $render = function (string $name, array $props) use ($page): string {
        $js = [];
        $css = [];

        ob_start();
        component($name, $props, $page, $js, $css);

        return (string) ob_get_clean();
    };

    // hero-section declares `:placeholder` as its image default.
    $cleared = $render('hero-section', ['title' => 'Hi', 'subtitle' => 'There', 'image' => '']);
    assert_contains('image-placeholder', $cleared, 'the placeholder comes back when the field is cleared');

    $missing = $render('hero-section', ['title' => 'Hi', 'subtitle' => 'There']);
    assert_contains('image-placeholder', $missing, 'and when the prop was never set');

    // A value the editor did choose wins over the placeholder.
    $chosen = $render('hero-section', ['title' => 'Hi', 'subtitle' => 'There', 'image' => 'https://example.test/pick.jpg']);
    assert_contains('https://example.test/pick.jpg', $chosen);
    assert_not_contains('image-placeholder', $chosen);

    // The theme names no placeholder files: the CMS owns the fallback, and a
    // default that named a file the theme does not ship would silently become
    // one too.
    $named = [];

    foreach (theme_config()['content_types'] as $type => $config) {
        foreach (($config['available_components'] ?? []) as $name) {
            $schema = content_component_definition((string) $name)['schema'] ?? [];

            foreach ($schema as $field => $rules) {
                if (!is_array($rules) || ($rules['type'] ?? '') !== 'image') {
                    continue;
                }

                $default = trim((string) ($rules['default'] ?? ''));

                if ($default !== '' && $default !== ':placeholder') {
                    $named[] = "{$name}.{$field} = {$default}";
                }
            }
        }
    }

    assert_count(0, $named, 'image defaults that name a file: ' . implode(', ', $named));

    // Only image fields are filled: a cleared text field stays empty.
    $text = $render('hero-section', ['title' => '', 'subtitle' => 'There', 'image' => '']);
    assert_not_contains('Default Title', $text, 'a cleared text field is not restored to its schema default');

    // An image field with no declared default stays empty.
    assert_eq(
        ['image' => ''],
        component_image_defaults(['image' => ''], ['image' => ['type' => 'image', 'default' => '']])
    );
});

/*
|--------------------------------------------------------------------------
| Manifest integrity
|--------------------------------------------------------------------------
| The validator takes the manifest and the theme directory as arguments, so a
| throwaway theme can be checked without touching theme/.
|
*/

/**
 * Write a throwaway theme directory and return its path.
 *
 * @param array<string, string> $files relative path => contents
 */
function theme_fixture(array $files): string
{
    $root = test_tmp_root() . '/theme-fixture';

    // Start from nothing: a file left behind by the previous fixture would be
    // scanned again and change the result. Previews sit in a subfolder, so the
    // clearing has to go all the way down.
    foreach (['layouts', 'components', 'partials', 'assets'] as $directory) {
        $path = $root . '/' . $directory;

        if (!is_dir($path)) {
            continue;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }

    foreach ($files as $relative => $contents) {
        $path = $root . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }

    return $root;
}

/**
 * The messages one group reported, joined for a substring assertion.
 *
 * @param array<string, list<array{status: string, message: string}>> $problems
 */
function theme_problem_messages(array $problems, string $group): string
{
    return implode(' | ', array_map(
        static fn(array $problem): string => (string) $problem['message'],
        $problems[$group] ?? []
    ));
}

/**
 * The status of the first problem in a group whose message contains a string.
 *
 * @param array<string, list<array{status: string, message: string}>> $problems
 */
function theme_problem_status(array $problems, string $group, string $needle): ?string
{
    foreach ($problems[$group] ?? [] as $problem) {
        if (str_contains((string) $problem['message'], $needle)) {
            return (string) $problem['status'];
        }
    }

    return null;
}

t('a consistent theme manifest reports no problems', function () {
    $path = theme_fixture([
        'layouts/default.php'         => "<?php\nreturn [];\n",
        'components/site-header.php'  => "<?php\nreturn ['label' => 'Header'];\n",
        'components/site-footer.php'  => "<?php\nreturn ['label' => 'Footer'];\n",
        'components/hero-section.php' => "<?php\nreturn ['label' => 'Hero'];\n",
        // A component that includes a partial which does exist.
        'components/pager-user.php'   => "<?php\nreturn ['label' => 'Pager', 'render' => function () { require theme('partials/pager.php'); }];\n",
        'partials/pager.php'          => "<?php\n// pager\n",
        'assets/style.css'            => "/* styles */\n",
        'assets/app.js'               => "// app\n",
        'assets/favicon.ico'          => "icon\n",
    ]);

    $theme = [
        'layouts'        => ['default' => 'Default'],
        'headers'        => ['site-header' => 'Header'],
        'footers'        => ['site-footer' => 'Footer'],
        'defaults'       => ['layout' => 'default', 'header' => 'site-header', 'footer' => 'site-footer'],
        'menu_locations' => ['main' => 'Main'],
        'content_types'  => [
            'page' => [
                'default_layout'       => 'default',
                'default_header'       => 'site-header',
                'default_footer'       => 'site-footer',
                'available_components' => ['hero-section', 'pager-user'],
            ],
        ],
        'form_types' => [
            'contact' => ['fields' => [
                'name'    => ['type' => 'text'],
                'subject' => ['type' => 'select', 'options' => ['general' => 'General']],
            ]],
        ],
        'icons'   => ['favicon' => 'favicon.ico'],
        'styles'  => ['style.css', 'style.css?v=4'],
        'scripts' => [['src' => 'app.js?v=2']],
    ];

    $problems = theme_manifest_problems($theme, $path, [
        'default_layout' => 'default',
        'default_header' => 'site-header',
        'default_footer' => 'site-footer',
    ]);

    $summary = [];

    foreach ($problems as $group => $list) {
        foreach ($list as $problem) {
            $summary[] = "{$group}: {$problem['message']}";
        }
    }

    assert_count(0, $summary, implode('; ', $summary));
});

t('a broken theme manifest names everything that does not resolve', function () {
    $path = theme_fixture([
        'layouts/default.php'           => "<?php\nreturn [];\n",
        'components/site-header.php'    => "<?php\nreturn ['label' => 'Header'];\n",
        'components/site-footer.php'    => "<?php\nreturn ['label' => 'Footer'];\n",
        // Reached from the palette, and wrong about itself in three ways.
        'components/broken-section.php' => "<?php\nreturn [\n    'label' => 'Broken',\n    'allowed_children' => ['ghost-child'],\n    'schema' => [\n        'menu' => ['type' => 'select', 'default' => 'sidebar'],\n        'content_type' => ['type' => 'select', 'default' => 'event'],\n    ],\n    'render' => function () { require theme('partials/gone.php'); },\n];\n",
        'partials/pager.php'            => "<?php\n// pager\n",
        'assets/style.css'              => "/* styles */\n",
        // A leftover from a rename, and one in a format the picker skips.
        'assets/previews/old-section.svg' => "<svg/>\n",
        'assets/previews/broken-section.gif' => "GIF89a\n",
    ]);

    $theme = [
        'layouts'        => ['default' => 'Default', 'blog' => 'Blog'],
        'headers'        => ['site-header' => 'Header', 'ghost-header' => 'Ghost'],
        'footers'        => ['site-footer' => 'Footer'],
        'defaults'       => ['layout' => 'default', 'header' => 'site-header', 'footer' => 'ghost-footer'],
        'search_layout'  => 'search',
        'menu_locations' => ['main' => 'Main'],
        'content_types'  => [
            'page' => [
                'default_layout'       => 'default',
                'default_header'       => 'site-header',
                'available_components' => ['broken-section', 'ghost-component'],
            ],
            'blog_post' => [
                'default_layout'       => 'blog',
                'taxonomy_layout'      => 'taxonomy',
                'available_components' => ['broken-section'],
            ],
        ],
        'form_types' => [
            'contact' => ['fields' => [
                'name'    => ['type' => 'text'],
                'colour'  => ['type' => 'wibble'],
                'subject' => ['type' => 'select'],
            ]],
        ],
        'icons'   => ['favicon' => 'favicon.ico'],
        'styles'  => ['style.css', 'missing.css?v=3'],
        'scripts' => [['src' => 'app.js?v=1']],
    ];

    $problems = theme_manifest_problems($theme, $path, [
        'default_layout' => 'gone-layout',
        'default_header' => 'site-header',
        'default_footer' => 'ghost-footer',
    ]);

    // Layouts: a declared layout, a content type's layouts, the search layout
    // and the setting the site actually renders.
    $layouts = theme_problem_messages($problems, 'layouts');

    foreach (['layouts.blog', 'blog_post.default_layout', 'blog_post.taxonomy_layout', 'search_layout', 'settings.default_layout'] as $needle) {
        assert_contains($needle, $layouts, "{$needle} should be reported");
    }

    // Components: missing files, a missing child, and the two field defaults
    // that only mislead the editor.
    $components = theme_problem_messages($problems, 'components');

    foreach (['headers.ghost-header', 'defaults.footer', 'ghost-component', 'ghost-child', 'sidebar', 'event'] as $needle) {
        assert_contains($needle, $components, "{$needle} should be reported");
    }

    assert_eq('fail', theme_problem_status($problems, 'components', 'ghost-child'), 'a missing child breaks the editor');
    assert_eq('warn', theme_problem_status($problems, 'components', "'sidebar'"), 'an undeclared menu slot only misleads');
    assert_eq('warn', theme_problem_status($problems, 'components', "'event'"), 'an undeclared content type only misleads');

    // Assets: a missing stylesheet or script breaks the page; an icon does not.
    assert_eq('fail', theme_problem_status($problems, 'assets', 'missing.css'));
    assert_eq('fail', theme_problem_status($problems, 'assets', 'app.js'));
    assert_eq('warn', theme_problem_status($problems, 'assets', 'favicon.ico'));

    assert_contains('partials/gone.php', theme_problem_messages($problems, 'partials'), 'a missing partial is fatal');

    // Previews: a file matching no component, and one the picker cannot read.
    $previews = theme_problem_messages($problems, 'previews');
    assert_contains('old-section.svg matches no component', $previews);
    assert_contains('broken-section.gif', $previews, 'an unreadable image type is reported');
    assert_eq('warn', theme_problem_status($problems, 'previews', 'old-section.svg'), 'a leftover preview only warns');

    $forms = theme_problem_messages($problems, 'forms');
    assert_contains("'wibble'", $forms, 'an unknown field type is reported');
    assert_contains('no options', $forms, 'a select with no options is reported');
    assert_eq('warn', theme_problem_status($problems, 'forms', 'wibble'), 'form field problems only warn');
});

t('the shipped theme has no manifest problems', function () {
    $problems = theme_manifest_problems(theme_config(), theme(), load_settings());

    $summary = [];

    foreach ($problems as $group => $list) {
        foreach ($list as $problem) {
            $summary[] = "{$group}: {$problem['message']}";
        }
    }

    assert_count(0, $summary, implode('; ', $summary));
});

t('asset() stamps theme assets with their modification time', function () {
    $style = CMS_PATH . '/theme/assets/style.css';
    assert_true(is_file($style), 'the theme stylesheet exists');

    assert_eq(url('theme/assets/style.css') . '?v=' . filemtime($style), asset('style.css'), 'a plain name is stamped');

    $icons = CMS_PATH . '/theme/assets/vendor/bootstrap-icons/bootstrap-icons.css';
    assert_eq(
        url('theme/assets/vendor/bootstrap-icons/bootstrap-icons.css') . '?v=' . filemtime($icons),
        asset('vendor/bootstrap-icons/bootstrap-icons.css'),
        'a nested path is stamped too'
    );

    // A CDN or any absolute URL is not ours to version.
    assert_eq('https://cdn.example.test/style.css', asset('https://cdn.example.test/style.css'));
    assert_eq('//cdn.example.test/style.css', asset('//cdn.example.test/style.css'));

    // A hand-written counter is replaced by the stamp, never appended to.
    $stamped = asset('style.css?v=5');
    assert_eq(asset('style.css'), $stamped, 'the old counter is dropped');
    assert_not_contains('?v=5', $stamped);

    // A file that is not there gets the plain URL rather than a version that
    // points at nothing.
    assert_eq(url('theme/assets/nope.css'), asset('nope.css'));
});

t('a versioned asset URL follows the file it points at', function () {
    $file = test_tmp_root() . '/versioned-asset.css';
    file_put_contents($file, 'a');

    touch($file, 1_700_000_000);
    clearstatcache(true, $file);
    assert_eq('/x.css?v=1700000000', version_asset_url('/x.css', $file));

    // Editing the file has to produce a new URL, or the browser keeps the copy
    // it cached.
    touch($file, 1_700_000_100);
    clearstatcache(true, $file);
    assert_eq('/x.css?v=1700000100', version_asset_url('/x.css', $file), 'a newer file means a new URL');

    unlink($file);
    clearstatcache(true, $file);
    assert_eq('/x.css', version_asset_url('/x.css', $file), 'a missing file is not stamped');
});

t('a rendered page versions its stylesheets and scripts', function () {
    $html = render_page(load_content_by_slug('about'))['body'];

    foreach (['style.css', 'utilities.css', 'layout.css', 'main.js'] as $asset) {
        $file = CMS_PATH . '/theme/assets/' . $asset;

        assert_contains(
            "theme/assets/{$asset}?v=" . filemtime($file),
            $html,
            "{$asset} should be stamped in the rendered head"
        );
    }

    // The old hand counters are gone from the manifest.
    assert_not_contains('?v=5', $html);
    assert_not_contains('?v=4', $html);
});

exit(test_summary());

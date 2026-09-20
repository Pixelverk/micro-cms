<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin design system
|--------------------------------------------------------------------------
| The admin stylesheet is the single source of styling for the admin UI.
| Inline <style> blocks were consolidated into it, and it defines the
| component classes the markup already used. These checks keep it that
| way: they are source-level because a missing class still renders, just
| unstyled — which is easy to miss in a browser.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

function admin_css(): string
{
    return (string) file_get_contents(CMS_PATH . '/admin/assets/style.css');
}

/**
 * Class names referenced by admin PHP and JS markup.
 *
 * @return array<string, list<string>> class => files
 */
function admin_markup_classes(): array
{
    $classes = [];

    $sources = array_merge(
        glob(CMS_PATH . '/admin/**/*.php') ?: [],
        glob(CMS_PATH . '/admin/*.php') ?: [],
        glob(CMS_PATH . '/admin/*/*.php') ?: [],
        glob(CMS_PATH . '/admin/*/*/*.php') ?: [],
        glob(CMS_PATH . '/admin/assets/*.js') ?: []
    );

    foreach (array_unique($sources) as $file) {
        $contents = (string) file_get_contents($file);

        if (preg_match_all('/class="([^"$`]*)"/', $contents, $matches)) {
            foreach ($matches[1] as $attribute) {
                foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                    if ($class !== '' && !str_contains($class, '{') && !str_contains($class, '?')) {
                        $classes[$class][] = str_replace(CMS_PATH . '/', '', $file);
                    }
                }
            }
        }
    }

    return $classes;
}

t('every class used in admin markup is defined in the design system', function () {
    $css = admin_css();
    $defined = array_flip(preg_match_all('/\.([a-zA-Z][\w-]*)/', $css, $m) ? $m[1] : []);

    // Behavioural hooks carry no styling on purpose.
    $hooks = ['js-confirm-form', 'messages-row', 'js-row-status', 'js-submission-view', 'js-submission-close', 'js-media-view', 'js-media-close'];

    $undefined = [];
    foreach (admin_markup_classes() as $class => $files) {
        if (isset($defined[$class]) || in_array($class, $hooks, true)) {
            continue;
        }

        $undefined[$class] = count($files);
    }

    $summary = implode(', ', array_map(
        fn($class, $count) => ".{$class} ({$count})",
        array_keys($undefined),
        $undefined
    ));

    assert_count(0, $undefined, 'undefined classes: ' . $summary);
});

t('the admin UI no longer ships inline <style> blocks', function () {
    $offenders = [];

    foreach (array_merge(glob(CMS_PATH . '/admin/*.php') ?: [], glob(CMS_PATH . '/admin/*/*.php') ?: [], glob(CMS_PATH . '/admin/*/*/*.php') ?: []) as $file) {
        if (str_contains((string) file_get_contents($file), '<style>')) {
            $offenders[] = str_replace(CMS_PATH . '/', '', $file);
        }
    }

    assert_count(0, $offenders, 'inline styles found in: ' . implode(', ', $offenders));
});

t('the design system defines a complete button set', function () {
    $css = admin_css();

    foreach (['.btn', '.btn-primary', '.btn-secondary', '.btn-muted', '.btn-info', '.btn-warning', '.btn-delete', '.btn-danger', '.btn-preview', '.btn-small'] as $selector) {
        assert_contains($selector, $css, "{$selector} should be styled");
    }
});

t('every icon named in admin markup exists in the icon set', function () {
    // icon() returns an HTML comment when the file is missing, which leaves an
    // icon-only button blank with no error. Only literal names are checkable at
    // source level; icon($var) calls are skipped.
    $missing = [];

    $files = array_merge(
        glob(CMS_PATH . '/admin/*.php') ?: [],
        glob(CMS_PATH . '/admin/*/*.php') ?: [],
        glob(CMS_PATH . '/admin/partials/*.php') ?: []
    );

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file);

        if (preg_match_all('/icon\(\s*[\x27"]([a-z0-9-]+)[\x27"]/', $contents, $matches)) {
            foreach ($matches[1] as $name) {
                if (!is_file(CMS_PATH . "/admin/assets/icons/{$name}.svg")) {
                    $missing[$name] = basename($file);
                }
            }
        }
    }

    $summary = implode(', ', array_map(
        static fn($name, $file) => "{$name} ({$file})",
        array_keys($missing),
        $missing
    ));

    assert_count(0, $missing, 'icons missing from admin/assets/icons: ' . $summary);
});

t('the design system defines the shared page furniture', function () {
    $css = admin_css();

    foreach ([
        '.page-header', '.page-title', '.page-actions',
        '.card', '.form-card', '.utility-action', '.empty-state',
        '.field', '.field-label', '.field-input', '.form-actions',
        '.content-table', '.admin-table', '.actions',
        '.badge', '.status', '.status-draft', '.status-scheduled', '.status-archived',
        '.modal', '.modal-content', '.sidebar', '.sidebar-link', '.header-title',
        '.toast', '.media-thumb', '.media-preview', '.image-grid', '.login-card',
    ] as $selector) {
        assert_contains($selector, $css, "{$selector} should be styled");
    }
});

t('the image picker ships both a select and a clear control', function () {
    // content-editor.js binds both by class on the cloned component template, so
    // losing one is a runtime error, not just a missing button.
    $template = (string) file_get_contents(CMS_PATH . '/admin/partials/content-editor-templates.php');
    $js       = (string) file_get_contents(CMS_PATH . '/admin/assets/content-editor.js');

    foreach (['select-image-btn', 'clear-image-btn'] as $class) {
        assert_contains($class, $template, "{$class} belongs in the picker template");
        assert_contains($class, $js, "{$class} is bound in content-editor.js");
    }
});

t('dark mode overrides the core surface tokens', function () {
    $css = admin_css();

    assert_contains('.dark', $css, 'a dark theme class exists');
    assert_contains('--surface:', $css);
    assert_contains('--text:', $css);
});

t('the header exposes accessible controls', function () {
    $header = (string) file_get_contents(CMS_PATH . '/admin/partials/header.php');

    assert_contains('header-title', $header, 'the bar names the current page');
    assert_contains('aria-label', $header, 'icon-only controls need labels');
    assert_contains('id="mobile-menu"', $header, 'mobile navigation toggle');
    assert_contains('current_username', $header, 'the account block names the signed-in editor');
});

t('the mobile navigation toggle is wired up in main.js', function () {
    $js = (string) file_get_contents(CMS_PATH . '/admin/assets/main.js');

    assert_contains('mobile-menu', $js);
    assert_contains('mobile-nav-open', $js);
    assert_contains('Escape', $js, 'keyboard users must be able to close the drawer');
});

t('every admin_trans() key is defined in the language file', function () {
    // A missing key silently renders the raw key in the UI, which is how the
    // documentation tab shipped showing "docs" instead of "Documentation".
    $english = require CMS_PATH . '/admin/lang/en.php';

    $files = array_merge(
        glob(CMS_PATH . '/admin/*.php') ?: [],
        glob(CMS_PATH . '/admin/*/*.php') ?: [],
        glob(CMS_PATH . '/admin/partials/*.php') ?: []
    );

    $missing = [];

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file);

        // Only literal keys: `admin_trans($var)` and `admin_trans('status_' . $x)`
        // are checked where they are built, below.
        if (preg_match_all('/admin_trans\(\s*[\x27"]([a-z0-9_]+)[\x27"]\s*[,)]/i', $contents, $matches)) {
            foreach ($matches[1] as $key) {
                if (!isset($english[$key])) {
                    $missing[$key] = basename($file);
                }
            }
        }
    }

    // The status dropdown builds its key from content_statuses().
    foreach (content_statuses() as $status) {
        if (!isset($english['status_' . $status])) {
            $missing['status_' . $status] = 'content_statuses()';
        }
    }

    $summary = implode(', ', array_map(
        static fn($key, $file) => "{$key} ({$file})",
        array_keys($missing),
        $missing
    ));

    assert_count(0, $missing, 'undefined translation keys: ' . $summary);
});

t('the Swedish translation covers the same keys as English', function () {
    $english = require CMS_PATH . '/admin/lang/en.php';
    $swedish = require CMS_PATH . '/admin/lang/sv.php';

    $missing = array_diff(array_keys($english), array_keys($swedish));

    assert_count(0, $missing, 'keys missing from sv.php: ' . implode(', ', $missing));
});

t('every admin page supplies a help panel', function () {
    // Each page that renders the layout should set $pageHelp, so the help
    // button always has something to show.
    $pages = array_merge(
        glob(CMS_PATH . '/admin/*.php') ?: [],
        glob(CMS_PATH . '/admin/*/*.php') ?: []
    );

    $without = [];

    foreach ($pages as $page) {
        $name = str_replace(CMS_PATH . '/admin/', '', $page);
        $contents = (string) file_get_contents($page);

        if (basename($page) === 'login.php' || basename($page) === 'logout.php') {
            continue;
        }

        // Only pages that actually render the admin layout are in scope.
        if (!str_contains($contents, 'partials/layout.php')) {
            continue;
        }

        if (!str_contains($contents, '$pageHelp')) {
            $without[] = $name;
        }
    }

    assert_count(0, $without, 'pages without a help panel: ' . implode(', ', $without));
});

t('the menu editor only queries a row\'s own controls', function () {
    $js = (string) file_get_contents(CMS_PATH . '/admin/assets/menu-editor.js');

    // A menu row contains other rows. The hidden switch sits after the children
    // container, so querySelector('[data-field="hidden"]') on a parent finds its
    // first child's switch and binds the parent's listener to the wrong node —
    // which is exactly how "hide this item" silently stopped working for parents.
    // Row-own controls are queried with :scope.
    foreach (["querySelector('[data-field=", "querySelectorAll('.field-input')"] as $unscoped) {
        assert_not_contains($unscoped, $js, "row controls need :scope: {$unscoped}");
    }
});

exit(test_summary());

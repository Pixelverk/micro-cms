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

t('the content editor keeps the sidebar beside the main column', function () {
    $editor = (string) file_get_contents(CMS_PATH . '/admin/content/edit.php');

    $columns = strpos($editor, 'class="editor-columns"');
    $main    = strpos($editor, '<div class="editor-main">');
    $mainEnd = strpos($editor, '<!-- /editor-main -->');
    $sidebar = strpos($editor, 'id="sidebar-container"');
    $formEnd = strrpos($editor, '</form>');

    assert_true(
        $columns !== false && $main !== false && $mainEnd !== false && $sidebar !== false && $formEnd !== false,
        'the editor has the columns, the main column and the sidebar'
    );
    assert_true(
        $columns < $main && $main < $mainEnd && $mainEnd < $sidebar && $sidebar < $formEnd,
        'the main column comes first, then the sidebar, all inside the form'
    );

    // The content type's own areas are all in the main column, in that order:
    // what is written, then details, images, and the SEO field cards.
    $body     = strpos($editor, 'class="card rich-text-container"');
    $details  = strpos($editor, 'editor_details');
    $images   = strpos($editor, 'editor_images');
    $seoField = strpos($editor, 'seo_editable_field_groups()');

    foreach (['body' => $body, 'details' => $details, 'images' => $images, 'seo' => $seoField] as $name => $position) {
        assert_true($position !== false && $position > $main && $position < $mainEnd, "{$name} is inside the main column");
    }

    assert_true($body < $details && $details < $images && $images < $seoField, 'they run body, details, images, SEO');

    // The SEO fields are one card per group; what they produce is previewed in
    // the sidebar, underneath the checklist.
    assert_contains('<?php foreach (seo_editable_field_groups() as $seoGroup): ?>', $editor, 'the SEO fields render one card per declared group');

    $searchPreview = strpos($editor, "editor_seo_preview_search_card");
    $socialPreview = strpos($editor, "editor_seo_preview_social_card");

    foreach (['search preview' => $searchPreview, 'social preview' => $socialPreview] as $name => $position) {
        assert_true($position !== false && $position > $sidebar && $position < $formEnd, "{$name} is in the sidebar");
    }

    assert_true($seoField < $searchPreview, 'the previews come after the fields they belong to');
    assert_contains('data-preview-title', $editor, 'the search preview is the snippet');
    assert_contains('data-preview-card-title', $editor, 'the social preview is the social card');

    // The fields are always on screen, and the cards are the last thing a
    // reader reaches: no disclosure control anywhere in the editor.
    assert_not_contains('<details', $editor, 'the SEO cards do not collapse');

    $css = (string) file_get_contents(CMS_PATH . '/admin/assets/style.css');

    // Each column keeps its own height: stretching a short one to the taller
    // one is what left a blank card under its last field.
    assert_contains('.editor-columns', $css);
    assert_contains('align-items: flex-start', $css, 'cards do not stretch to the taller column');
    assert_contains('.editor-main', $css, 'the main column has its own rule');
    assert_contains('grid-template-columns: repeat(auto-fit, minmax(240px, 1fr))', $css, 'the SEO fields read as a grid');

    // The details column scrolls with the page; only it carries that class.
    $sidebarRule = substr($css, (int) strpos($css, '.sidebar-container'), 220);
    assert_not_contains('position: sticky', $sidebarRule, 'the content editor column does not stick');
});

t('the editor is properly nested markup', function () {
    $form = (string) file_get_contents(CMS_PATH . '/admin/content/edit.php');
    $form = substr($form, (int) strpos($form, 'content-editor-form'));

    // The columns close before the form does. This is the one the browser hid:
    // `</form>` implicitly closes an open element, so a missing `</div>` never
    // showed up as a broken page.
    $columnsEnd = strpos($form, '<!-- /editor-columns -->');
    $formEnd    = strpos($form, '</form>');

    assert_true($columnsEnd !== false && $formEnd !== false && $columnsEnd < $formEnd, 'the columns close inside the form');
    assert_contains("    </div>\n    <!-- /editor-columns -->", $form, 'the columns div itself is closed');

    // Every container the editor opens is closed by the time the form ends.
    $open  = preg_match_all('/<div[\s>]/', $form);
    $close = substr_count($form, '</div>');

    assert_eq($open, $close, 'every <div> in the editor is closed');
    assert_eq(substr_count($form, '<fieldset'), substr_count($form, '</fieldset>'), 'every <fieldset> is closed');
});

t('the icon field offers the theme\'s icons as a browser', function () {
    $js = (string) file_get_contents(CMS_PATH . '/admin/assets/content-editor.js');

    assert_contains("case 'icon': tpl = iconTemplate", $js, 'the field type has its own template');
    assert_contains("document.querySelectorAll('#icon-picker [data-icon]')", $js, 'the browser\'s glyphs are the tiles');
    assert_contains('function showIconPreview', $js);

    $template = (string) file_get_contents(CMS_PATH . '/admin/partials/content-editor-templates.php');
    assert_contains('id="icon-template"', $template);
    assert_contains('data-icon-picker', $template, 'the field stores the icon name');

    // Components ask for an icon by name; the theme decides what exists.
    assert_contains("'type' => 'icon'", (string) file_get_contents(CMS_PATH . '/theme/components/feature-card.php'));
    assert_contains("'type' => 'icon'", (string) file_get_contents(CMS_PATH . '/theme/components/contact-section.php'));
});

t('the component editor keeps the Add area under the last component', function () {
    $js = (string) file_get_contents(CMS_PATH . '/admin/assets/content-editor.js');

    // The Add area is a child of the component container, so a new component has
    // to go in above it, and only components may sort — otherwise one ends up
    // below the area that adds them.
    assert_contains('appendComponent(', $js, 'components are inserted through one helper');
    assert_contains("draggable: '.component'", $js, 'the Add area is never sorted');
});

t('the menu editor keeps its row controls usable while the whole row drags', function () {
    $js = (string) file_get_contents(CMS_PATH . '/admin/assets/menu-editor.js');

    // The whole row is the drag handle and Sortable filters the controls out of
    // the drag. Left at its default, preventOnFilter also calls preventDefault on
    // a filtered element's mousedown, so a click on a field never focuses it and
    // the row reads as dead. The two options have to travel together.
    assert_contains('filter:', $js, 'controls stay out of the drag');
    assert_contains('preventOnFilter: false', $js, 'and keep their own mouse behaviour');
});

t('the theme and the admin offer a skip link to a marked main landmark', function () {
    $render = (string) file_get_contents(CMS_PATH . '/core/render.php');
    assert_contains('function render_skip_link', $render);
    assert_contains('class="skip-link" href="#main-content"', $render);

    $layouts = glob(CMS_PATH . '/theme/layouts/*.php') ?: [];
    assert_true(count($layouts) > 0, 'the theme ships layouts');

    $without = [];
    foreach ($layouts as $layout) {
        if (!str_contains((string) file_get_contents($layout), 'id="main-content"')) {
            $without[] = basename($layout);
        }
    }

    assert_count(0, $without, 'layouts with no main landmark: ' . implode(', ', $without));

    $adminLayout = (string) file_get_contents(CMS_PATH . '/admin/partials/layout.php');
    assert_contains('class="skip-link"', $adminLayout, 'the admin has a skip link');
    assert_contains('<main id="main-content">', $adminLayout, 'and a matching target');

    // The auth pages are standalone documents outside that layout, so each
    // carries its own pair — and the link comes first, or it is not a skip link.
    foreach (['login.php', 'forgot-password.php', 'reset-password.php'] as $authPage) {
        $markup = (string) file_get_contents(CMS_PATH . '/admin/auth/' . $authPage);

        assert_contains('class="skip-link" href="#main-content"', $markup, "{$authPage} has a skip link");
        assert_contains('<main id="main-content">', $markup, "{$authPage} has a matching target");
        assert_true(
            strpos($markup, 'class="skip-link"') < strpos($markup, '<main id="main-content">'),
            "{$authPage} offers the skip link before its main landmark"
        );
    }
});

t('every dialog is a labelled modal that the helper can manage', function () {
    foreach ([
        'admin/partials/confirm.php',
        'admin/partials/image-picker.php',
        'admin/partials/component-picker.php',
        'admin/partials/icon-picker.php',
        'admin/utilities.php',
        'admin/messages.php',
        'admin/media/index.php',
    ] as $file) {
        $markup = (string) file_get_contents(CMS_PATH . '/' . $file);

        assert_contains('role="dialog"', $markup, "{$file} names its dialogs");
        assert_contains('aria-modal="true"', $markup, "{$file} marks them modal");
        assert_contains('aria-labelledby', $markup, "{$file} labels them");
    }

    $helper = (string) file_get_contents(CMS_PATH . '/admin/assets/main.js');

    foreach (['function openDialog', 'function closeDialog', "'Escape'", "'Tab'"] as $needle) {
        assert_contains($needle, $helper, "the dialog helper handles {$needle}");
    }
});

t('the editor\'s glyph-only controls carry names', function () {
    $template = (string) file_get_contents(CMS_PATH . '/admin/partials/content-editor-templates.php');

    foreach ([
        'move-up'       => 'editor_move_up',
        'move-down'     => 'editor_move_down',
        'duplicate-btn' => 'editor_duplicate',
        'remove-btn'    => 'common_remove',
    ] as $class => $key) {
        assert_contains('class="' . $class . '"', $template, "{$class} is still there");
        assert_contains("admin_trans('{$key}')", $template, "{$class} has an accessible name");
    }

    // An inline icon is decoration; the control around it carries the name.
    assert_contains('aria-hidden="true"', icon('eye', 16), 'icons are hidden from AT');
    assert_contains('focusable="false"', icon('eye', 16), 'and cannot take focus');
});

t('status feedback is announced and the landmarks are named', function () {
    $toasts = (string) file_get_contents(CMS_PATH . '/admin/partials/toasts.php');
    assert_contains('id="toast-container" role="status"', $toasts, 'admin toasts are a live region');

    $form = (string) file_get_contents(CMS_PATH . '/theme/partials/form.php');
    assert_contains('class="message" role="status"', $form, 'public form feedback is a live region');

    $sidebar = (string) file_get_contents(CMS_PATH . '/admin/partials/sidebar.php');
    assert_contains('admin_trans(\'nav_aria_main\')', $sidebar, 'the admin nav is named');

    $header = (string) file_get_contents(CMS_PATH . '/theme/components/site-header.php');
    assert_contains('aria-label="Main"', $header, 'the public nav is named');
});

exit(test_summary());

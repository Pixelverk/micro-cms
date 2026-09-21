<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Site health
|--------------------------------------------------------------------------
|
| A read-only report of the things that quietly break an install: missing
| extensions, storage the web user cannot write, a stale schema and unsafe
| production settings. admin/health.php renders it; nothing here writes.
|
| Check text is plain English and lives here so the same array can be tested
| without rendering a page.
|
*/

/**
 * One row of the report.
 *
 * @param string $status 'ok', 'warn' or 'fail'
 * @return array{label: string, status: string, detail: string, fix: string}
 */
function health_result(string $label, string $status, string $detail, string $fix = ''): array
{
    return ['label' => $label, 'status' => $status, 'detail' => $detail, 'fix' => $fix];
}

/*
|--------------------------------------------------------------------------
| Theme manifest integrity
|--------------------------------------------------------------------------
|
| Nothing else validates the manifest. A layout that no longer exists throws a
| blank 500 out of render_layout(), a missing header or component prints
| "component not found" into the page, and a missing partial is a fatal
| require — all of them silent until a visitor arrives.
|
| Only what the manifest reaches is checked: a component file nobody lists is
| not an error, and the placeholder child name in core/components/sample-
| component.php is not part of any palette. Everything resolves theme-first
| with the core/components fallback, exactly as component() does it.
|--------------------------------------------------------------------------
*/

/**
 * Problems with a theme manifest, grouped by area.
 *
 * @param array<string, mixed> $theme
 * @param string $themePath the theme directory (theme())
 * @param array<string, mixed> $settings current settings, for the selections the site actually uses
 * @return array<string, list<array{status: string, message: string}>> group => problems
 */
function theme_manifest_problems(array $theme, string $themePath, array $settings): array
{
    $problems  = [];
    $themePath = rtrim($themePath, '/');

    $add = static function (string $group, string $status, string $message) use (&$problems): void {
        $problems[$group][] = ['status' => $status, 'message' => $message];
    };

    // Component files by name. The theme wins over core, as at render time.
    $componentFiles = [];

    foreach ([CORE_PATH . '/components', $themePath . '/components'] as $directory) {
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $componentFiles[basename($file, '.php')] = $file;
        }
    }

    $layoutFiles = [];

    foreach (glob($themePath . '/layouts/*.php') ?: [] as $file) {
        $layoutFiles[basename($file, '.php')] = true;
    }

    $contentTypes = is_array($theme['content_types'] ?? null) ? $theme['content_types'] : [];

    // ------------------------------------------------------------- layouts
    $layoutReferences = [];

    foreach (array_keys((array) ($theme['layouts'] ?? [])) as $layout) {
        $layoutReferences[] = ["layouts.{$layout}", (string) $layout];
    }

    foreach ($contentTypes as $type => $config) {
        if (!is_array($config)) {
            continue;
        }

        foreach (['default_layout', 'taxonomy_layout'] as $key) {
            $layout = trim((string) ($config[$key] ?? ''));

            if ($layout !== '') {
                $layoutReferences[] = ["{$type}.{$key}", $layout];
            }
        }
    }

    $namedLayouts = [
        'search_layout'  => (string) ($theme['search_layout'] ?? ''),
        'defaults.layout' => (string) ($theme['defaults']['layout'] ?? ''),
        // The setting beats the manifest, so it is the one that renders.
        'settings.default_layout' => (string) ($settings['default_layout'] ?? ''),
    ];

    foreach ($namedLayouts as $key => $layout) {
        if (trim($layout) !== '') {
            $layoutReferences[] = [$key, trim($layout)];
        }
    }

    foreach ($layoutReferences as [$key, $layout]) {
        if (!isset($layoutFiles[$layout])) {
            $add('layouts', 'fail', "{$key} names '{$layout}', but theme/layouts/{$layout}.php does not exist");
        }
    }

    // ---------------------------------------------------------- components
    $componentReferences = [];
    $available           = [];

    foreach (['headers', 'footers'] as $key) {
        foreach (array_keys((array) ($theme[$key] ?? [])) as $name) {
            $componentReferences[] = ["{$key}.{$name}", (string) $name];
            $available[(string) $name] = true;
        }
    }

    foreach ($contentTypes as $type => $config) {
        if (!is_array($config)) {
            continue;
        }

        foreach ((array) ($config['available_components'] ?? []) as $name) {
            $componentReferences[] = ["{$type}.available_components", (string) $name];
            $available[(string) $name] = true;
        }

        foreach (['default_header', 'default_footer'] as $key) {
            $name = trim((string) ($config[$key] ?? ''));

            if ($name !== '') {
                $componentReferences[] = ["{$type}.{$key}", $name];
            }
        }
    }

    $namedComponents = [
        'defaults.header'         => (string) ($theme['defaults']['header'] ?? ''),
        'defaults.footer'         => (string) ($theme['defaults']['footer'] ?? ''),
        'settings.default_header' => (string) ($settings['default_header'] ?? ''),
        'settings.default_footer' => (string) ($settings['default_footer'] ?? ''),
    ];

    foreach ($namedComponents as $key => $name) {
        if (trim($name) !== '') {
            $componentReferences[] = [$key, trim($name)];
        }
    }

    foreach ($componentReferences as [$key, $name]) {
        if (!isset($componentFiles[$name])) {
            $add('components', 'fail', "{$key} names '{$name}', which has no component file in theme/components or core/components");
        }
    }

    // A content type edited as rich text writes the one component it names, so
    // that component must exist and carry the quill field the editor renders.
    // An editor mode nothing recognises would silently fall back, so it is said
    // here too.
    foreach ($contentTypes as $type => $config) {
        if (!is_array($config)) {
            continue;
        }

        $mode = (string) ($config['editor'] ?? '');

        if ($mode === '') {
            continue;
        }

        if ($mode !== 'components' && $mode !== 'rich-text') {
            $add('components', 'warn', "{$type}.editor is '{$mode}', which is not an editor mode; the component editor is used instead");
            continue;
        }

        if ($mode !== 'rich-text') {
            continue;
        }

        $editor = content_rich_text_editor($config + ['available_components' => []]);

        if ($editor === null) {
            $add('components', 'fail', "{$type}.editor is 'rich-text', but none of its available_components declares a quill field");
        } elseif (!isset($componentFiles[$editor['component']])) {
            $add('components', 'fail', "{$type}.editor is 'rich-text', but its component '{$editor['component']}' has no component file");
        }
    }

    // What the components the palette reaches declare about themselves.
    foreach (array_keys($available) as $name) {
        if (!isset($componentFiles[$name])) {
            continue; // Already reported above.
        }

        $component = require $componentFiles[$name];

        if (!is_array($component)) {
            $add('components', 'fail', "{$name} does not return an array");
            continue;
        }

        foreach ((array) ($component['allowed_children'] ?? []) as $child) {
            if (!isset($componentFiles[(string) $child])) {
                $add('components', 'fail', "{$name} allows child '{$child}', which has no component file");
            }
        }

        $schema = is_array($component['schema'] ?? null) ? $component['schema'] : [];

        // Only a select is filled from the manifest, so only a select can
        // disagree with it.
        if (is_array($schema['menu'] ?? null) && ($schema['menu']['type'] ?? '') === 'select') {
            $default = trim((string) ($schema['menu']['default'] ?? ''));

            if ($default !== '' && !isset($theme['menu_locations'][$default])) {
                $add('components', 'warn', "{$name}'s menu default '{$default}' is not a declared menu_location");
            }
        }

        if (is_array($schema['content_type'] ?? null) && ($schema['content_type']['type'] ?? '') === 'select') {
            $default = trim((string) ($schema['content_type']['default'] ?? ''));

            if ($default !== '' && !isset($contentTypes[$default])) {
                $add('components', 'warn', "{$name}'s content_type default '{$default}' is not a declared content type");
            }
        }
    }

    // ------------------------------------------------------------ previews
    // The component picker looks for theme/assets/previews/<component> with one
    // of a few image extensions. Either half being wrong is invisible in the
    // editor — the tile just stays blank — so it is worth a warning.
    foreach (glob($themePath . '/assets/previews/*') ?: [] as $file) {
        $base = basename($file);
        $name = pathinfo($file, PATHINFO_FILENAME);

        if (!isset($componentFiles[$name])) {
            $add('previews', 'warn', "assets/previews/{$base} matches no component");
        } elseif (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
            $add('previews', 'warn', "assets/previews/{$base} is not an image type the picker shows");
        }
    }

    // -------------------------------------------------------------- assets
    $assetReferences = [];

    foreach ((array) ($theme['styles'] ?? []) as $style) {
        $assetReferences['styles'][] = (string) $style;
    }

    foreach ((array) ($theme['scripts'] ?? []) as $script) {
        $assetReferences['scripts'][] = is_array($script) ? (string) ($script['src'] ?? '') : '';
    }

    foreach ((array) ($theme['icons'] ?? []) as $key => $icon) {
        // icons.app is a list of the square PNGs an installed site uses; the
        // rest name one file each.
        foreach (is_array($icon) ? $icon : [$icon] as $entry) {
            $assetReferences['icons.' . $key][] = (string) $entry;
        }
    }

    foreach ($assetReferences as $key => $values) {
        // A missing icon costs a broken image; a missing stylesheet or script
        // leaves the page unstyled or without its behaviour.
        $status = str_starts_with($key, 'icons.') ? 'warn' : 'fail';

        foreach ($values as $value) {
            if (trim($value) === '') {
                $add('assets', 'fail', "{$key} has an empty entry");
                continue;
            }

            // A CDN or any absolute URL is not ours to check.
            if (preg_match('#^(?:https?:)?//#i', $value)) {
                continue;
            }

            $relative = ltrim(explode('?', $value, 2)[0], '/');

            if (!is_file($themePath . '/assets/' . $relative)) {
                $add('assets', $status, "theme/assets/{$relative} is missing ({$key})");
            }
        }
    }

    // ------------------------------------------------------------ partials
    $themeFiles = array_merge(
        glob($themePath . '/layouts/*.php') ?: [],
        glob($themePath . '/components/*.php') ?: [],
        glob($themePath . '/partials/*.php') ?: []
    );

    $partialReferences = [];

    foreach ($themeFiles as $file) {
        if (preg_match_all('/theme\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', (string) file_get_contents($file), $matches)) {
            foreach ($matches[1] as $reference) {
                if (str_starts_with($reference, 'partials/')) {
                    $partialReferences[$reference] = true;
                }
            }
        }
    }

    foreach (array_keys($partialReferences) as $reference) {
        if (!is_file($themePath . '/' . $reference)) {
            $add('partials', 'fail', "{$reference} is included by a layout or component but does not exist");
        }
    }

    // --------------------------------------------------------------- forms
    $knownFieldTypes = form_submission_field_types();

    foreach ((array) ($theme['form_types'] ?? []) as $formType => $config) {
        if (!is_array($config)) {
            $add('forms', 'fail', "form type '{$formType}' is not a definition");
            continue;
        }

        $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];

        if ($fields === []) {
            $add('forms', 'warn', "form type '{$formType}' declares no fields");
        }

        foreach ($fields as $field => $rules) {
            if (!is_array($rules)) {
                $add('forms', 'fail', "{$formType}.{$field} is not a field definition");
                continue;
            }

            $type = (string) ($rules['type'] ?? (!empty($rules['email']) ? 'email' : 'text'));

            if (!in_array($type, $knownFieldTypes, true)) {
                $add('forms', 'warn', "{$formType}.{$field} uses an unknown field type '{$type}'");
            }

            if (in_array($type, ['select', 'radio'], true) && empty($rules['options'])) {
                $add('forms', 'warn', "{$formType}.{$field} is a {$type} with no options");
            }
        }
    }

    return $problems;
}

/**
 * Every check, in report order.
 *
 * @return list<array{label: string, status: string, detail: string, fix: string}>
 */
function health_checks(): array
{
    $checks = [];
    $env    = (string) config('env', 'production');

    // ---------------------------------------------------------- PHP & modules
    $phpOk = PHP_VERSION_ID >= 80000;
    $checks[] = health_result(
        'PHP version',
        $phpOk ? 'ok' : 'fail',
        PHP_VERSION . ' (8.0 or newer required)',
        $phpOk ? '' : 'Upgrade PHP to 8.0 or newer.'
    );

    // pdo_sqlite and imagick are real requirements.
    foreach (['pdo_sqlite', 'imagick'] as $extension) {
        $loaded = extension_loaded($extension);

        $checks[] = $loaded
            ? health_result("Extension: {$extension}", 'ok', 'Loaded')
            : health_result("Extension: {$extension}", 'fail', 'Missing (required)', "Install the php-{$extension} extension.");
    }

    // Zip is optional: ZipArchive is preferred and PharData is the fallback.
    if (extension_loaded('zip')) {
        $checks[] = health_result('Extension: zip', 'ok', 'Loaded');
    } elseif (class_exists('PharData')) {
        $checks[] = health_result('Extension: zip', 'ok', 'Not loaded; the static export and backups use the Phar fallback.');
    } else {
        $checks[] = health_result(
            'Extension: zip',
            'warn',
            'Missing, with no Phar fallback; the static export and backups are unavailable.',
            'Install the php-zip extension.'
        );
    }

    // ------------------------------------------------------------- database
    if (!is_file(STORAGE_PATH . '/data.sqlite')) {
        $checks[] = health_result(
            'Database',
            'fail',
            'storage/data.sqlite is missing.',
            'Reload the site so the installer can create it.'
        );
    } elseif (!database_is_writable()) {
        $checks[] = health_result(
            'Database',
            'fail',
            'storage/data.sqlite is not writable by the web user.',
            'chown the storage directory to the web server user.'
        );
    } else {
        $checks[] = health_result('Database', 'ok', 'storage/data.sqlite is writable.');
    }

    // -------------------------------------------------------------- storage
    $directories = [
        'storage/'         => STORAGE_PATH,
        'storage/cache/'   => STORAGE_PATH . '/cache',
        'storage/media/'   => STORAGE_PATH . '/media',
        'storage/logs/'    => STORAGE_PATH . '/logs',
        // Only used while an uploaded content package waits for confirmation.
        'storage/imports/' => STORAGE_PATH . '/imports',
    ];

    foreach ($directories as $label => $directory) {
        if (!is_dir($directory)) {
            $checks[] = health_result($label, 'warn', 'Directory does not exist yet.', 'Create it, or load the site once.');
        } elseif (!is_writable($directory)) {
            $checks[] = health_result($label, 'fail', 'Not writable by the web user.', "chmod or chown {$label} so the web server can write.");
        } else {
            $checks[] = health_result($label, 'ok', 'Writable.');
        }
    }

    $sitemap = STORAGE_PATH . '/sitemap.xml';
    if (is_file($sitemap) && !is_writable($sitemap)) {
        $checks[] = health_result('sitemap.xml', 'warn', 'Not writable.', 'chmod storage/sitemap.xml, or regenerating it will fail.');
    } elseif (is_file($sitemap)) {
        $checks[] = health_result('sitemap.xml', 'ok', 'Writable.');
    }

    // --------------------------------------------------------------- schema
    $registry = migrate_registry();
    $newest   = array_key_last($registry);
    $marker   = migrate_marker_path();
    $current  = $newest === null || (is_file($marker) && trim((string) @file_get_contents($marker)) === $newest);

    $checks[] = health_result(
        'Schema',
        $current ? 'ok' : 'warn',
        $current ? 'The migration marker matches the registry.' : 'Migrations are pending.',
        $current ? '' : 'Open Utilities and run the migrations.'
    );

    // --------------------------------------------------------------- theme
    // Everything the manifest names has to exist, or the page that uses it is a
    // blank 500, an unstyled page or a "component not found" placeholder.
    $themeProblems = theme_manifest_problems(theme_config(), theme(), load_settings());

    $themeGroups = [
        'layouts'    => ['Theme layouts', 'Every declared layout resolves, and the settings select one that exists.'],
        'components' => ['Theme components', 'Every declared header, footer, component and child name resolves.'],
        'assets'     => ['Theme assets', 'Every declared stylesheet, script and icon exists.'],
        'partials'   => ['Theme partials', 'Every partial a layout or component includes exists.'],
        'forms'      => ['Theme form fields', 'Every declared form field is well formed.'],
    ];

    foreach ($themeGroups as $group => [$label, $okDetail]) {
        $found = $themeProblems[$group] ?? [];

        if (!$found) {
            $checks[] = health_result($label, 'ok', $okDetail);
            continue;
        }

        $status = 'ok';

        foreach ($found as $problem) {
            if (($problem['status'] ?? 'fail') === 'fail') {
                $status = 'fail';
                break;
            }

            $status = 'warn';
        }

        // Four is enough to show what is wrong; the rest are only counted, so
        // the row stays readable.
        $shown = array_slice($found, 0, 4);

        $detail = implode('; ', array_map(
            static fn(array $problem): string => (string) $problem['message'],
            $shown
        ));

        if (count($found) > count($shown)) {
            $detail .= '; and ' . (count($found) - count($shown)) . ' more';
        }

        $checks[] = health_result(
            $label,
            $status,
            $detail,
            'Add the missing file, or remove the name from theme/theme.php.'
        );
    }

    // -------------------------------------------------------------- config
    $setup = config('setup_completed') === true;
    $checks[] = health_result(
        'Setup completed',
        $setup ? 'ok' : 'warn',
        $setup ? 'config.php is marked as set up.' : 'config.php still has setup_completed => false.',
        $setup ? '' : 'Set setup_completed to true in config.php.'
    );

    $secret = (string) (config('security.form_secret') ?? '');
    $checks[] = health_result(
        'Form secret',
        $secret !== '' ? 'ok' : 'warn',
        $secret !== '' ? 'security.form_secret is set.' : 'No form secret; tokens fall back to a value derived from the install path.',
        $secret !== '' ? '' : 'Set security.form_secret to a random string.'
    );

    $unsafe = [];
    if ($env === 'production' && config('perf_logging') === true) {
        $unsafe[] = 'perf_logging is on';
    }
    if ($env === 'production' && filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN)) {
        $unsafe[] = 'display_errors is on';
    }

    $checks[] = health_result(
        'Environment',
        $unsafe ? 'warn' : 'ok',
        $unsafe ? $env . ', but ' . implode(' and ', $unsafe) : $env,
        $unsafe ? 'Turn these off on a production site.' : ''
    );

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    $checks[] = health_result(
        'HTTPS',
        $https ? 'ok' : 'warn',
        $https ? 'This request is encrypted.' : 'This request is not HTTPS.',
        $https ? '' : 'Install a TLS certificate.'
    );

    // ---------------------------------------------------------------- disk
    $free = @disk_free_space(STORAGE_PATH);

    if ($free === false) {
        $checks[] = health_result('Free disk space', 'warn', 'Could not be read.');
    } else {
        $megabytes = (int) round($free / 1048576);
        $checks[] = health_result(
            'Free disk space',
            $megabytes < 100 ? 'warn' : 'ok',
            $megabytes . ' MB free',
            $megabytes < 100 ? 'Free some space before uploads start failing.' : ''
        );
    }

    return $checks;
}

/**
 * Counts by status.
 *
 * @param list<array{status: string}> $checks
 * @return array{ok: int, warn: int, fail: int}
 */
function health_summary(array $checks): array
{
    $summary = ['ok' => 0, 'warn' => 0, 'fail' => 0];

    foreach ($checks as $check) {
        $status = $check['status'] ?? 'warn';

        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    }

    return $summary;
}

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
        'storage/'       => STORAGE_PATH,
        'storage/cache/' => STORAGE_PATH . '/cache',
        'storage/media/' => STORAGE_PATH . '/media',
        'storage/logs/'  => STORAGE_PATH . '/logs',
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

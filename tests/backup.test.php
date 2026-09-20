<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Backup
|--------------------------------------------------------------------------
|
| backup_build() zips the whole site — the code, the database, the media
| library, the sitemap and the migration marker — laid out the way the install
| is, with config.php's form secret removed and the regenerable cache, the
| logs, the sessions, the import stash and the test suite left out. The backend
| is ZipArchive when present, otherwise the PharData fallback; neither exists on
| some hosts.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('backup_build() refuses clearly when no zip backend is available', function () {
    if (zip_available()) {
        return; // a backend exists, so the guard does not apply
    }

    try {
        backup_build();
    } catch (RuntimeException $exception) {
        assert_contains('zip', strtolower($exception->getMessage()));
        return;
    }

    throw new RuntimeException('expected backup_build() to refuse without a zip backend');
});

t('the entry list is the install, without the host state', function () {
    file_put_contents(STORAGE_PATH . '/media/backup-test.txt', 'media file');

    try {
        $entries = backup_entries(STORAGE_PATH . '/data.sqlite');
        $names   = array_column($entries, 'name');
        $all     = implode("\n", $names);

        // The code, so the archive runs on the new host.
        foreach ([
            'index.php',
            '.htaccess',
            'core/helpers/export.php',
            'admin/utilities.php',
            'admin/assets/style.css',
            'theme/components/hero-section.php',
        ] as $expected) {
            assert_true(in_array($expected, $names, true), "{$expected} is in the archive");
        }

        // The data and the readme.
        foreach (['storage/data.sqlite', 'storage/media/backup-test.txt', 'BACKUP-README.txt'] as $expected) {
            assert_true(in_array($expected, $names, true), "{$expected} is in the archive");
        }

        assert_contains('Micro CMS - whole-site backup', (string) end($entries)['content'], 'the readme travels with it');

        // Nothing that belongs to the host it came from.
        foreach (['tests/', '.git/', 'storage/cache/', 'storage/logs/', 'storage/sessions/', 'storage/imports/'] as $absent) {
            assert_not_contains($absent, $all, "{$absent} is not in the archive");
        }

        assert_not_contains('backup-data.sqlite', $all, 'the temporary snapshot is not inside the archive');
    } finally {
        @unlink(STORAGE_PATH . '/media/backup-test.txt');
    }
});

t('config.php travels without its form secret and with nothing else changed', function () {
    $scratch = test_tmp_root() . '/backup-config';
    @mkdir($scratch, 0777, true);

    // The test config's secret, written the way an install would hold it.
    $secret = (string) config('security.form_secret');
    assert_true($secret !== '', 'the test install configures a form secret');

    $path = $scratch . '/config.php';
    file_put_contents($path, "<?php\nreturn [\n    // Kept comments stay.\n    'security' => [\n        'form_secret' => '{$secret}',\n        'login_max_attempts' => 5,\n    ],\n];\n");

    $scrubbed = backup_config_contents($path);

    assert_contains("'form_secret' => null", $scrubbed, 'the secret is replaced with null');
    assert_contains('Kept comments stay', $scrubbed, 'everything else is left alone');
    assert_not_contains($secret, $scrubbed, 'the secret is gone');

    // A secret that is not a literal in the file is not in the archive to begin
    // with, so the file passes through unchanged.
    $fromEnvironment = $scratch . '/config-env.php';
    file_put_contents($fromEnvironment, "<?php\nreturn ['security' => ['form_secret' => getenv('CMS_FORM_SECRET') ?: null]];\n");

    assert_eq(
        (string) file_get_contents($fromEnvironment),
        backup_config_contents($fromEnvironment),
        'a secret that is read, not written, is left alone'
    );

    // A secret left somewhere the rewrite cannot reach is refused rather than
    // shipped: an archive in a cloud folder must not carry it in a comment.
    $stubborn = $scratch . '/config-stubborn.php';
    file_put_contents($stubborn, "<?php\n// generated with the form secret '{$secret}'\nreturn ['security' => ['form_secret' => null]];\n");

    try {
        try {
            backup_config_contents($stubborn);
        } catch (RuntimeException $exception) {
            assert_contains('form secret', $exception->getMessage());
            return; // the cleanup below still runs
        }

        throw new RuntimeException('expected an unremovable secret to be refused');
    } finally {
        @unlink($path);
        @unlink($fromEnvironment);
        @unlink($stubborn);
        @rmdir($scratch);
    }
});

t('backup_build() packages the site and leaves the host state out', function () {
    if (!zip_available()) {
        return; // covered by the guard test above
    }

    // Give the archive something to carry, and host state it must ignore.
    file_put_contents(STORAGE_PATH . '/media/backup-test.txt', 'media file');
    file_put_contents(STORAGE_PATH . '/sitemap.xml', '<urlset></urlset>');
    cache_write('backup-test', '<html>cached</html>');
    @mkdir(STORAGE_PATH . '/imports', 0777, true);
    file_put_contents(STORAGE_PATH . '/imports/backup-test.json', '{}');

    $archive = backup_build();

    assert_true(is_file($archive), 'an archive is written');

    $names = zip_entry_names($archive);
    $all   = implode("\n", $names);

    foreach ([
        'index.php',
        'core/helpers/export.php',
        'theme/components/hero-section.php',
        'storage/data.sqlite',
        'storage/media/backup-test.txt',
        'storage/sitemap.xml',
        'config.php',
        'BACKUP-README.txt',
    ] as $expected) {
        assert_true(in_array($expected, $names, true), "{$expected} is included");
    }

    foreach (['tests/', '.git/', 'storage/cache', 'storage/logs', 'storage/sessions', 'storage/imports'] as $absent) {
        assert_not_contains($absent, $all, "{$absent} is not included");
    }

    assert_not_contains('backup-data.sqlite', $all, 'the temporary copy is not inside the archive');

    @unlink($archive);
    @unlink(STORAGE_PATH . '/media/backup-test.txt');
    @unlink(STORAGE_PATH . '/sitemap.xml');
    @unlink(STORAGE_PATH . '/imports/backup-test.json');
    @rmdir(STORAGE_PATH . '/imports');
    @unlink(cache_file_for('backup-test'));
    @unlink(STORAGE_PATH . '/backup-data.sqlite');
});

exit(test_summary());

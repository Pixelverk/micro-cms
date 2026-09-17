<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Backup
|--------------------------------------------------------------------------
|
| backup_build() zips the data — database, media and sitemap — and leaves the
| regenerable cache out. The backend is ZipArchive when present, otherwise the
| PharData fallback; neither exists on some hosts.
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

t('backup_build() packages the data and leaves the cache out', function () {
    if (!zip_available()) {
        return; // covered by the guard test above
    }

    // Give the archive something to carry, and a cache file it must ignore.
    file_put_contents(STORAGE_PATH . '/media/backup-test.txt', 'media file');
    file_put_contents(STORAGE_PATH . '/sitemap.xml', '<urlset></urlset>');
    cache_write('backup-test', '<html>cached</html>');

    $archive = backup_build();

    assert_true(is_file($archive), 'an archive is written');

    $names = zip_entry_names($archive);
    $all   = implode("\n", $names);

    assert_true(in_array('data.sqlite', $names, true), 'the database is included');
    assert_true(in_array('media/backup-test.txt', $names, true), 'media is included');
    assert_true(in_array('sitemap.xml', $names, true), 'the sitemap is included');

    assert_not_contains('cache/', $all, 'the cache is not included');
    assert_not_contains('config.php', $all, 'config.php is never included');
    assert_not_contains('sessions', $all, 'sessions are never included');
    assert_not_contains('backup-data.sqlite', $all, 'the temporary copy is not inside the archive');

    @unlink($archive);
    @unlink(STORAGE_PATH . '/media/backup-test.txt');
    @unlink(STORAGE_PATH . '/sitemap.xml');
    @unlink(cache_file_for('backup-test'));
    @unlink(STORAGE_PATH . '/backup-data.sqlite');
});

exit(test_summary());

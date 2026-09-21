<?php
declare(strict_types=1);



/*
|--------------------------------------------------------------------------
| Backup
|--------------------------------------------------------------------------
|
| A zip of the whole site, laid out the way the install is, so the archive can
| be unzipped into a new web root and run: the code, the consistent database
| snapshot, the media library, the sitemap and the migration marker. The cache
| is excluded because it is regenerable; logs, sessions, the import stash, the
| test suite and the planning documents are not needed on a new host. config.php
| travels with its form secret removed, and BACKUP-README.txt explains the
| restore. Restore is manual — see the in-app documentation.
|
*/

/**
 * The files a whole-site backup contains.
 *
 * Split from backup_build() so the list can be verified without the zip
 * extension, the way static_export_entries() is.
 *
 * @param string $dbSnapshot path of the consistent database copy to include
 * @return list<array{name: string, content?: string, source?: string}>
 */
function backup_entries(string $dbSnapshot): array
{
    $entries = [];

    // The code, laid out as it sits on disk. A stray log or editor file from a
    // development machine is not part of the site.
    foreach (['core', 'admin', 'theme'] as $directory) {
        $root = CMS_PATH . '/' . $directory;

        foreach (export_directory_files($root) as $file) {
            if (preg_match('#(?:/\.DS_Store$|/Thumbs\.db$|\.(?:log|tmp)$)#i', $file)) {
                continue;
            }

            $entries[] = [
                'name'   => $directory . '/' . substr($file, strlen($root) + 1),
                'source' => $file,
            ];
        }
    }

    // The front controller and the Apache routing contract.
    foreach (['index.php', '.htaccess'] as $file) {
        if (is_file(CMS_PATH . '/' . $file)) {
            $entries[] = ['name' => $file, 'source' => CMS_PATH . '/' . $file];
        }
    }

    if (is_file(CMS_PATH . '/config.php')) {
        $entries[] = [
            'name'    => 'config.php',
            'content' => backup_config_contents(CMS_PATH . '/config.php'),
        ];
    }

    // The data: the database snapshot, the media library, the sitemap, and the
    // marker that says which migrations the restored database has already run.
    $entries[] = ['name' => 'storage/data.sqlite', 'source' => $dbSnapshot];

    foreach (export_directory_files(STORAGE_PATH . '/media') as $file) {
        $entries[] = [
            'name'   => 'storage/media/' . substr($file, strlen(STORAGE_PATH . '/media') + 1),
            'source' => $file,
        ];
    }

    foreach (['sitemap.xml', '.migrations'] as $file) {
        if (is_file(STORAGE_PATH . '/' . $file)) {
            $entries[] = ['name' => 'storage/' . $file, 'source' => STORAGE_PATH . '/' . $file];
        }
    }

    $entries[] = ['name' => 'BACKUP-README.txt', 'content' => backup_readme()];

    return $entries;
}


/**
 * config.php as it goes into the archive, with the form secret taken out.
 *
 * An archive is a download that ends up on other people's disks, and the form
 * secret signs public form tokens. Only a literal in the file can leak: a
 * secret read from the environment or built at runtime is not in there at all.
 * If the configured secret is still in the text after the rewrite, the file is
 * refused rather than shipped.
 *
 * @param string $path the config file to read, so the rewrite can be tested
 *                     without touching the install's own config.php
 */
function backup_config_contents(string $path): string
{
    $contents = (string) file_get_contents($path);
    $secret   = config('security.form_secret');

    if (!is_string($secret) || $secret === '') {
        return $contents; // Nothing configured, so nothing to remove.
    }

    // The key's value, whatever it was set to, becomes null.
    $contents = (string) preg_replace(
        "/(['\"]form_secret['\"]\s*=>\s*)(?:null|'[^']*'|\"[^\"]*\")/",
        '$1null',
        $contents
    );

    if (str_contains($contents, $secret)) {
        throw new RuntimeException(
            'The form secret still appears in config.php in a place this backup cannot remove (a comment, or a value built from it). Remove it there, or set security.form_secret to null, and try again.'
        );
    }

    return $contents;
}


/**
 * The note that travels inside the archive: what it is, and how to put it back.
 */
function backup_readme(): string
{
    $title = trim((string) get_setting('site_title', ''));
    $url   = site_origin();
    $when  = format_date(time(), 'Y-m-d H:i');

    return <<<TXT
Micro CMS - whole-site backup

Site:     {$title}
Address:  {$url}
Created:  {$when}

What is in here
  The PHP code (index.php, .htaccess, core/, admin/, theme/), the database
  (storage/data.sqlite), the media library, the sitemap and the migration
  marker. config.php is included with security.form_secret removed.

Putting it back
  1. Unzip the archive into the web root, so index.php and core/ land where the
     site should run.
  2. Make storage/ writable by the web server user.
  3. In config.php set "url" to the site's address (or leave it empty for a
     domain root) and "env" to "production"; keep "setup_completed" true.
  4. Point the virtual host at the folder and make sure .htaccess is honoured
     (AllowOverride All).

What is not in here
  The page cache, the logs, the sessions, the import stash and the test suite.
  The cache rebuilds on the next visit; the rest belongs to the old host.

The form secret
  security.form_secret was removed, so public form tokens fall back to a value
  derived from the install path. Set a new random secret in config.php if the
  site's forms are in use.
TXT;
}


/**
 * Build a whole-site backup zip. Returns its path.
 */
function backup_build(): string
{
    if (!zip_available()) {
        throw new RuntimeException('Backups need the PHP zip or phar extension, and neither is available.');
    }

    $dbPath  = STORAGE_PATH . '/data.sqlite';
    $dbCopy  = STORAGE_PATH . '/backup-data.sqlite';
    $archive = STORAGE_PATH . '/backup.zip';

    if (!is_file($dbPath)) {
        throw new RuntimeException('The database file is missing.');
    }

    @unlink($dbCopy);

    // VACUUM INTO gives a consistent snapshot even while the site is writing.
    try {
        $pdo = db();
        $pdo->exec('VACUUM INTO ' . $pdo->quote($dbCopy));
    } catch (Throwable $exception) {
        // Older SQLite builds: a plain copy is better than no backup.
        if (!@copy($dbPath, $dbCopy)) {
            throw new RuntimeException('Could not copy the database for the backup.');
        }
    }

    // The snapshot is a working file, wherever the archive ends up.
    try {
        zip_write($archive, backup_entries($dbCopy));
    } finally {
        @unlink($dbCopy);
    }

    return $archive;
}

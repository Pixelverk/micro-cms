<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cache warm-up, static export and backup
|--------------------------------------------------------------------------
|
| The Utilities page can render every published page ahead of the first
| visitor, and package the warmed pages, theme assets and media into a zip.
| Cache files are written through cache_write() so the rename-into-place
| rule stays in one place.
|
*/

/**
 * Public request paths of every published, due content item.
 *
 * The front page is omitted: it is served from the root request, and its own
 * slug would collide with the root in the cache key.
 *
 * @return list<string>
 */
function published_content_paths(): array
{
    $theme      = theme_config();
    $settings   = load_settings();
    $prefixes   = $settings['content_prefixes'] ?? [];
    $homepageId = (int) ($settings['homepage_id'] ?? 0);
    $pdo        = db();
    $now        = time();

    $paths = [];

    foreach (array_keys($theme['content_types'] ?? []) as $type) {
        $stmt = $pdo->prepare("
            SELECT id, slug, parent_id
            FROM content
            WHERE type = :type
              AND status = 'published'
              AND published_at IS NOT NULL
              AND published_at <= :now
              AND deleted_at IS NULL
        ");
        $stmt->execute(['type' => $type, 'now' => $now]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $prefix = $prefixes[$type] ?? '';

        foreach ($items as $item) {
            if ($homepageId > 0 && (int) $item['id'] === $homepageId) {
                continue;
            }

            // Nested pages keep their parent segments, so the path matches
            // what the router resolves.
            $full    = build_full_slug($item, $items);
            $paths[] = trim(($prefix ? $prefix . '/' : '') . $full, '/');
        }
    }

    return array_values(array_unique(array_filter($paths, 'strlen')));
}

/**
 * Render and cache every published page, including the front page.
 *
 * A page that cannot be resolved or rendered is reported instead of
 * aborting the run, so one broken page does not stop the rest.
 *
 * @return array{rendered: int, failed: list<string>}
 */
function warm_cache(): array
{
    // Maintenance mode closes the public site. Warming it would recreate the
    // cache files that the 503 path relies on not existing.
    if (maintenance_mode_enabled()) {
        return ['rendered' => 0, 'failed' => []];
    }

    require_once CORE_PATH . '/render.php';

    $homepageId = (int) (load_settings()['homepage_id'] ?? 0);

    $requests = published_content_paths();

    // The front page is its own cache entry, keyed by an empty request path.
    if ($homepageId > 0) {
        array_unshift($requests, '');
    }

    $rendered = 0;
    $failed   = [];

    foreach ($requests as $request) {
        $label = $request === '' ? '/' : $request;

        try {
            $page = $request === '' ? load_content_by_id($homepageId) : load_content_by_slug($request);

            if (!$page || ($page['status'] ?? '') !== 'published') {
                $failed[] = $label;
                continue;
            }

            // The path drives canonical URLs; the front page has none.
            $page['path'] = $request;

            $response = render_page($page);

            if (($response['status'] ?? 200) !== 200) {
                $failed[] = $label;
                continue;
            }

            if (!cache_write($request, (string) $response['body'])) {
                $failed[] = $label;
                continue;
            }

            $rendered++;
        } catch (Throwable $exception) {
            debug_log("cache warm failed for '{$label}': " . $exception->getMessage());
            $failed[] = $label;
        }
    }

    return ['rendered' => $rendered, 'failed' => $failed];
}

/*
|--------------------------------------------------------------------------
| Static export
|--------------------------------------------------------------------------
|
| A zip of the warmed pages, theme assets and media. Pages are written as
| <path>/index.html so the pretty URLs keep working, and asset/media URLs
| become relative to each page so the result runs without PHP.
|
*/

/**
 * Every file below a directory, recursively.
 *
 * @return list<string>
 */
function export_directory_files(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * Rewrite theme and media URLs relative to the page's own directory.
 *
 * Page-to-page links are already absolute paths and are left alone. The
 * lookbehind keeps an external URL that merely contains "/media/" intact.
 */
function static_rewrite_urls(string $html, string $base, string $request): string
{
    $depth  = count(array_filter(explode('/', trim($request, '/')), 'strlen'));
    $prefix = str_repeat('../', $depth);

    foreach (['/theme/assets/', '/media/'] as $path) {
        $pattern = "#(?<=['\"(\\s,])" . preg_quote($base . $path, '#') . '#';
        $html    = preg_replace($pattern, $prefix . ltrim($path, '/'), $html) ?? $html;
    }

    return $html;
}

/**
 * The files a static export contains: one index.html per published page
 * plus the theme assets and media library.
 *
 * Split from export_static_site() so the entry list can be verified without
 * the zip extension.
 *
 * @return list<array{name: string, content?: string, source?: string}>
 */
function static_export_entries(): array
{
    $base       = rtrim((string) config('url', ''), '/');
    $homepageId = (int) (load_settings()['homepage_id'] ?? 0);

    $requests = published_content_paths();

    // The front page is stored as the root index.html.
    if ($homepageId > 0) {
        array_unshift($requests, '');
    }

    $entries = [];

    foreach ($requests as $request) {
        $cacheFile = cache_file_for($request);

        if (!is_file($cacheFile)) {
            continue;
        }

        $entries[] = [
            'name'    => $request === '' ? 'index.html' : trim($request, '/') . '/index.html',
            'content' => static_rewrite_urls((string) file_get_contents($cacheFile), $base, $request),
        ];
    }

    foreach (export_directory_files(theme('assets')) as $file) {
        $entries[] = [
            'name'   => 'theme/assets/' . substr($file, strlen(theme('assets')) + 1),
            'source' => $file,
        ];
    }

    foreach (export_directory_files(STORAGE_PATH . '/media') as $file) {
        $entries[] = [
            'name'   => 'media/' . substr($file, strlen(STORAGE_PATH . '/media') + 1),
            'source' => $file,
        ];
    }

    return $entries;
}

/**
 * Warm the cache and package it as a zip.
 *
 * @return string Absolute path of the created archive.
 */
function export_static_site(): string
{
    if (!zip_available()) {
        throw new RuntimeException('Static export needs the PHP zip or phar extension, and neither is available.');
    }

    warm_cache();

    $archive = STORAGE_PATH . '/static-site.zip';
    zip_write($archive, static_export_entries());

    return $archive;
}

/*
|--------------------------------------------------------------------------
| Backup
|--------------------------------------------------------------------------
|
| A zip of the data, not the pages: the database, the media library and the
| sitemap. The cache is excluded because it is regenerable; config.php (which
| holds the form secret), sessions and logs are runtime state and stay out too.
| Restore is manual — see the in-app documentation.
|
*/

/**
 * Build a backup zip of the data. Returns its path.
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

    $entries = [['name' => 'data.sqlite', 'source' => $dbCopy]];

    foreach (export_directory_files(STORAGE_PATH . '/media') as $file) {
        $entries[] = [
            'name'   => 'media/' . substr($file, strlen(STORAGE_PATH . '/media') + 1),
            'source' => $file,
        ];
    }

    if (is_file(STORAGE_PATH . '/sitemap.xml')) {
        $entries[] = ['name' => 'sitemap.xml', 'source' => STORAGE_PATH . '/sitemap.xml'];
    }

    zip_write($archive, $entries);
    @unlink($dbCopy);

    return $archive;
}

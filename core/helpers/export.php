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

    // The virtual documents. Served per request on a live site, they would be
    // missing from an export unless they are written out with everything else.
    if (is_file(STORAGE_PATH . '/sitemap.xml')) {
        $entries[] = ['name' => 'sitemap.xml', 'source' => STORAGE_PATH . '/sitemap.xml'];
    }

    if (function_exists('robots_txt')) {
        $entries[] = ['name' => 'robots.txt', 'content' => robots_txt()];
    }

    if (function_exists('seo_manifest_json')) {
        $entries[] = ['name' => 'site.webmanifest', 'content' => seo_manifest_json()];
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
    $url   = seo_site_url();
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

/*
|--------------------------------------------------------------------------
| Content package
|--------------------------------------------------------------------------
|
| A theme ships its demo content as two documents — content.json and
| settings.json — and the same pair moves live content between installs. An
| import takes either, or both, so a package is also how content travels.
|
| Nothing carries a database id: parents, taxonomy links and the homepage are
| named as "<type>:<slug path>" and resolved on import, because the receiving
| database assigns its own ids. Media is deliberately absent — a demo
| references theme image filenames, which resolve_image_value() already
| handles, and uploaded binaries belong to the site that uploaded them.
|
*/

const CONTENT_PACKAGE_FORMAT = 1;

/**
 * The settings a package may carry.
 *
 * Only what describes the site's content. Environment and operations settings
 * (site_url, timezone, admin language, media sizes, contact email) and raw
 * admin-only ones (custom CSS, header and footer scripts, robots rules,
 * maintenance) are excluded, because a package that carried site_url or
 * timezone would wreck the install it landed on.
 *
 * @return list<string>
 */
function content_package_setting_keys(): array
{
    return [
        'site_title',
        'site_description',
        'title_suffix',
        'default_layout',
        'default_header',
        'default_footer',
        'content_prefixes',
        'menu_locations',
    ];
}

/**
 * Content rows with ids as integers and a full slug path each.
 *
 * @return array{rows: list<array<string, mixed>>, paths: array<int, string>}
 */
function content_package_rows(): array
{
    $rows = db()->query("SELECT * FROM content WHERE deleted_at IS NULL ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $index => $row) {
        $rows[$index]['id']        = (int) $row['id'];
        $rows[$index]['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
    }

    $paths = [];

    foreach ($rows as $row) {
        $paths[$row['id']] = build_full_slug($row, $rows);
    }

    return ['rows' => $rows, 'paths' => $paths];
}

/**
 * The theme's own demo package.
 *
 * A theme keeps its demo in theme/demo/content.json and theme/demo/settings.json,
 * so the content a theme developer works with lives beside their components and
 * the installer has no opinion about what a site contains.
 *
 * @return array{documents: list<array<string, mixed>>, errors: list<string>}
 */
function content_package_theme_demo(): array
{
    $documents = [];
    $errors    = [];

    foreach (['content', 'settings'] as $part) {
        $file = theme('demo/' . $part . '.json');

        if (!is_file($file)) {
            continue;
        }

        $parsed = content_package_parse((string) file_get_contents($file));

        if ($parsed['document'] === null) {
            $errors[] = basename($file) . ': ' . $parsed['error'];
            continue;
        }

        $documents[] = $parsed['document'];
    }

    return ['documents' => $documents, 'errors' => $errors];
}

/**
 * The content document: everything the editor owns except media.
 *
 * @return array<string, mixed>
 */
function content_package_export_content(): array
{
    $exported = content_package_rows();
    $rows     = $exported['rows'];
    $paths    = $exported['paths'];

    $byId = [];

    foreach ($rows as $row) {
        $byId[$row['id']] = $row;
    }

    $content = [];

    foreach ($rows as $row) {
        $item = [
            'type'  => (string) $row['type'],
            'path'  => $paths[$row['id']],
            'title' => (string) $row['title'],
            'status' => (string) $row['status'],
        ];

        // Optional when it matches what the theme would default to anyway.
        foreach (['layout', 'header', 'footer'] as $key) {
            if (!empty($row[$key])) {
                $item[$key] = (string) $row[$key];
            }
        }

        if ($row['parent_id'] !== null && isset($paths[$row['parent_id']])) {
            $parentType     = (string) ($byId[$row['parent_id']]['type'] ?? $row['type']);
            $item['parent'] = $parentType . ':' . $paths[$row['parent_id']];
        }

        $item['meta'] = json_decode((string) $row['meta'], true) ?: [];
        $item['body'] = json_decode((string) $row['body'], true) ?: [];

        foreach (['published_at', 'scheduled_at', 'created_at', 'updated_at'] as $key) {
            if ($row[$key] !== null) {
                $item[$key] = (int) $row[$key];
            }
        }

        $content[] = $item;
    }

    $taxonomies = [];

    foreach (db()->query("SELECT * FROM taxonomy ORDER BY id ASC") as $term) {
        $taxonomies[] = [
            'type'         => (string) $term['taxonomy_type'],
            'content_type' => (string) $term['content_type'],
            'name'         => (string) $term['name'],
            'slug'         => (string) $term['slug'],
            'description'  => (string) ($term['description'] ?? ''),
            'content'      => [],
        ];
    }

    // Attach the content each term is used by, so the links travel with it.
    $termIndex = [];

    foreach ($taxonomies as $index => $term) {
        $termIndex[$term['type'] . ':' . $term['content_type'] . ':' . $term['slug']] = $index;
    }

    $links = db()->query("
        SELECT r.taxonomy_id, r.content_id, t.taxonomy_type, t.content_type, t.slug
        FROM taxonomy_term_relationships r
        JOIN taxonomy t ON t.id = r.taxonomy_id
    ");

    foreach ($links as $link) {
        $key = $link['taxonomy_type'] . ':' . $link['content_type'] . ':' . $link['slug'];
        $row = $byId[(int) $link['content_id']] ?? null;

        if ($row === null || $row['type'] !== $link['content_type'] || !isset($termIndex[$key])) {
            continue;
        }

        $taxonomies[$termIndex[$key]]['content'][] = $row['type'] . ':' . $paths[$row['id']];
    }

    $menus = [];

    foreach (list_menus() as $menu) {
        $menus[] = [
            'label' => (string) $menu['label'],
            'slug'  => (string) $menu['slug'],
            // Ids belong to the site that wrote them. The slug travels, and the
            // importer resolves it against the content it just landed.
            'items' => menu_items_strip_ids($menu['items']),
        ];
    }

    return [
        'format'     => CONTENT_PACKAGE_FORMAT,
        'content'    => $content,
        'taxonomies' => $taxonomies,
        'menus'      => $menus,
    ];
}

/**
 * The settings document, including the homepage as a content reference.
 *
 * @return array<string, mixed>
 */
function content_package_export_settings(): array
{
    $settings = load_settings();
    $values   = [];

    foreach (content_package_setting_keys() as $key) {
        if (array_key_exists($key, $settings)) {
            $values[$key] = $settings[$key];
        }
    }

    // The homepage is stored as an id; a package names it by slug instead.
    $homepageId = (int) ($settings['homepage_id'] ?? 0);

    if ($homepageId > 0) {
        $exported = content_package_rows();

        foreach ($exported['rows'] as $row) {
            if ($row['id'] === $homepageId) {
                $values['homepage'] = $row['type'] . ':' . $exported['paths'][$homepageId];
                break;
            }
        }
    }

    return [
        'format'   => CONTENT_PACKAGE_FORMAT,
        'settings' => $values,
    ];
}

/**
 * A document as the JSON a package file holds.
 */
function content_package_json(array $document): string
{
    return json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n";
}

/**
 * Decode one uploaded package file.
 *
 * @return array{document: array<string, mixed>|null, error: string}
 */
function content_package_parse(string $json): array
{
    $document = json_decode($json, true);

    if (!is_array($document)) {
        return ['document' => null, 'error' => 'That file is not JSON.'];
    }

    $format = (int) ($document['format'] ?? 0);

    if ($format !== CONTENT_PACKAGE_FORMAT) {
        return ['document' => null, 'error' => 'Unsupported package format ' . ($format ?: 'none') . '.'];
    }

    if (!isset($document['content']) && !isset($document['settings'])) {
        return ['document' => null, 'error' => 'That file carries neither content nor settings.'];
    }

    return ['document' => $document, 'error' => ''];
}

/**
 * Merge parsed documents into one package.
 *
 * @param list<array<string, mixed>> $documents
 * @return array{package: array<string, mixed>, problems: list<string>}
 */
function content_package_merge(array $documents): array
{
    $package = [
        'content'    => [],
        'taxonomies' => [],
        'menus'      => [],
        'settings'   => [],
        'has_content'  => false,
        'has_settings' => false,
    ];

    $problems = [];

    foreach ($documents as $document) {
        if (isset($document['content'])) {
            if ($package['has_content']) {
                $problems[] = 'Two files both define content; import one at a time.';
            }

            $package['has_content']  = true;
            $package['content']      = is_array($document['content']) ? $document['content'] : [];
            $package['taxonomies']   = is_array($document['taxonomies'] ?? null) ? $document['taxonomies'] : [];
            $package['menus']        = is_array($document['menus'] ?? null) ? $document['menus'] : [];
        }

        if (isset($document['settings'])) {
            if ($package['has_settings']) {
                $problems[] = 'Two files both define settings; import one at a time.';
            }

            $package['has_settings'] = true;
            $package['settings']     = is_array($document['settings']) ? $document['settings'] : [];
        }
    }

    if (!$package['has_content'] && !$package['has_settings']) {
        $problems[] = 'Nothing to import.';
    }

    return ['package' => $package, 'problems' => $problems];
}

/**
 * What an import would do, and everything wrong with the package.
 *
 * Problems are fatal: they are why an import is refused rather than applied in
 * part. Warnings are reported and skipped.
 *
 * @param array<string, mixed> $package
 * @return array<string, mixed>
 */
function content_package_plan(array $package): array
{
    $theme        = theme_config();
    $contentTypes = $theme['content_types'] ?? [];

    $problems = [];
    $warnings = [];

    $refs = [];

    // ------------------------------------------------------------- content
    if ($package['has_content']) {
        foreach ($package['content'] as $index => $item) {
            if (!is_array($item)) {
                $problems[] = "Content item {$index} is not an object.";
                continue;
            }

            $type = (string) ($item['type'] ?? '');
            $path = trim((string) ($item['path'] ?? ''), '/');

            if ($type === '' || $path === '') {
                $problems[] = "Content item {$index} has no type or path.";
                continue;
            }

            if (!isset($contentTypes[$type])) {
                $problems[] = "Unknown content type '{$type}' ({$type}:{$path}).";
                continue;
            }

            $ref = $type . ':' . $path;

            if (isset($refs[$ref])) {
                $problems[] = "{$ref} appears twice.";
                continue;
            }

            $refs[$ref] = true;

            foreach (['layout' => 'layouts', 'header' => 'headers', 'footer' => 'footers'] as $key => $declared) {
                $value = trim((string) ($item[$key] ?? ''));

                if ($value !== '' && !isset($theme[$declared][$value])) {
                    $problems[] = "{$ref} uses an undeclared {$key} '{$value}'.";
                }
            }

            foreach (content_package_component_types($item['body'] ?? []) as $component) {
                if (!content_package_component_exists($component)) {
                    $problems[] = "{$ref} uses a missing component '{$component}'.";
                } elseif (!in_array($component, $contentTypes[$type]['available_components'] ?? [], true)) {
                    // Renderable, but the editor would not offer it: worth
                    // saying, not worth refusing.
                    $warnings[] = "{$ref} uses '{$component}', which {$type} does not offer in the editor.";
                }
            }
        }

        // Parents and taxonomy links have to point at something in the package.
        foreach ($package['content'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $parent = trim((string) ($item['parent'] ?? ''));

            if ($parent !== '' && !isset($refs[$parent])) {
                $problems[] = ($item['type'] ?? '') . ':' . ($item['path'] ?? '') . " has a parent '{$parent}' that is not in the package.";
            }
        }

        foreach ($package['taxonomies'] as $term) {
            if (!is_array($term)) {
                continue;
            }

            $termType = (string) ($term['type'] ?? '');

            if (!in_array($termType, ['category', 'tag'], true)) {
                $problems[] = "Taxonomy '{$term['slug']}' has unknown type '{$termType}'.";
            }

            if (!isset($contentTypes[(string) ($term['content_type'] ?? '')])) {
                $problems[] = "Taxonomy '{$term['slug']}' belongs to an unknown content type.";
            }

            foreach ((array) ($term['content'] ?? []) as $ref) {
                if (!isset($refs[(string) $ref])) {
                    $problems[] = "Taxonomy '{$term['slug']}' is attached to '{$ref}', which is not in the package.";
                }
            }
        }
    }

    // ------------------------------------------------------------ settings
    if ($package['has_settings']) {
        $allowed = content_package_setting_keys();

        foreach (array_keys($package['settings']) as $key) {
            if ($key !== 'homepage' && !in_array((string) $key, $allowed, true)) {
                $problems[] = "Setting '{$key}' cannot travel in a package.";
            }
        }
    }

    // ------------------------------------------------- homepage resolution
    $homepage = ['ref' => '', 'resolves' => false, 'note' => ''];

    if ($package['has_settings'] && isset($package['settings']['homepage'])) {
        $ref  = (string) $package['settings']['homepage'];
        $homepage['ref'] = $ref;

        if (isset($refs[$ref])) {
            $homepage['resolves'] = true;
            $homepage['note']     = 'Taken from this package.';
        } else {
            $existing = content_package_find_ref($ref);

            if ($existing !== null) {
                $homepage['resolves'] = true;
                $homepage['note']     = 'Matched against the content already on this site.';
            } else {
                $homepage['note'] = 'Not in the package and not on this site: the homepage is left as it is.';
                $warnings[]       = "The homepage '{$ref}' could not be resolved, so it was left unchanged.";
            }
        }
    }

    // Content without a homepage setting still moves the homepage: it is
    // re-matched by path afterwards, so say so (and say what happens if not).
    if ($package['has_content'] && !isset($package['settings']['homepage'])) {
        $before = content_package_ref_of((int) (load_settings()['homepage_id'] ?? 0));

        if ($before !== '') {
            $warnings[] = isset($refs[$before])
                ? "The homepage '{$before}' is in the package and will follow it."
                : "The homepage '{$before}' is not in the package, so the homepage will be unset.";
        }
    }

    $settingsChanges = [];

    if ($package['has_settings']) {
        $current = load_settings();

        foreach ($package['settings'] as $key => $value) {
            if ($key === 'homepage') {
                continue;
            }

            $from = $current[$key] ?? null;

            if ($from !== $value) {
                $settingsChanges[] = [
                    'key'  => (string) $key,
                    'from' => is_scalar($from) ? (string) $from : json_encode($from),
                    'to'   => is_scalar($value) ? (string) $value : json_encode($value),
                ];
            }
        }
    }

    return [
        'problems'     => $problems,
        'warnings'     => $warnings,
        'has_content'  => (bool) $package['has_content'],
        'has_settings' => (bool) $package['has_settings'],
        'create'       => [
            'content'    => $package['has_content'] ? count($package['content']) : 0,
            'taxonomies' => $package['has_content'] ? count($package['taxonomies']) : 0,
            'menus'      => $package['has_content'] ? count($package['menus']) : 0,
            'settings'   => count($settingsChanges),
        ],
        'delete'       => [
            'content'    => $package['has_content'] ? content_package_count('content', 'deleted_at IS NULL') : 0,
            'taxonomies' => $package['has_content'] ? content_package_count('taxonomy') : 0,
            'menus'      => $package['has_content'] ? content_package_count('menus') : 0,
        ],
        'settings'     => $settingsChanges,
        'homepage'     => $homepage,
    ];
}

/**
 * Every component type named in a component tree.
 *
 * @param mixed $components
 * @return list<string>
 */
function content_package_component_types(mixed $components): array
{
    $types = [];

    foreach ((array) $components as $component) {
        if (!is_array($component)) {
            continue;
        }

        $type = (string) ($component['type'] ?? '');

        if ($type !== '') {
            $types[] = $type;
        }

        $types = array_merge($types, content_package_component_types($component['children'] ?? []));
    }

    return array_values(array_unique($types));
}

/**
 * Does this theme (or core) have that component file?
 */
function content_package_component_exists(string $name): bool
{
    return is_file(theme("components/{$name}.php")) || is_file(CORE_PATH . "/components/{$name}.php");
}

/**
 * What a content id points at, as a "<type>:<path>" reference, or ''.
 */
function content_package_ref_of(int $id): string
{
    if ($id <= 0) {
        return '';
    }

    $exported = content_package_rows();

    foreach ($exported['rows'] as $row) {
        if ($row['id'] === $id) {
            return $row['type'] . ':' . $exported['paths'][$id];
        }
    }

    return '';
}

/**
 * Find existing content by "<type>:<path>", or null.
 *
 * @return array<string, mixed>|null
 */
function content_package_find_ref(string $ref): ?array
{
    $parts = explode(':', $ref, 2);

    if (count($parts) !== 2) {
        return null;
    }

    $exported = content_package_rows();

    foreach ($exported['rows'] as $row) {
        if ($row['type'] === $parts[0] && $exported['paths'][$row['id']] === trim($parts[1], '/')) {
            return $row;
        }
    }

    return null;
}

/**
 * Row count for one table, optionally filtered.
 */
function content_package_count(string $table, string $where = ''): int
{
    $sql = "SELECT COUNT(*) FROM {$table}" . ($where !== '' ? " WHERE {$where}" : '');

    return (int) db()->query($sql)->fetchColumn();
}

/**
 * Apply a validated package.
 *
 * Content, taxonomies and menus move as one unit: a menu whose pages are gone
 * is worse than no menu. Settings move separately, so a content-only import
 * leaves the site's configuration alone.
 *
 * @param array<string, mixed> $package
 * @param array<string, mixed> $options 'sitemap' => false to skip writing it (a fresh install has no origin yet)
 * @return array{content: int, taxonomies: int, links: int, menus: int, settings: int, homepage: string}
 */
function content_package_import(array $package, array $options = []): array
{
    $pdo     = db();
    $now     = time();
    $summary = ['content' => 0, 'taxonomies' => 0, 'links' => 0, 'menus' => 0, 'settings' => 0, 'homepage' => '', 'warnings' => []];

    // Replacing content replaces every id, so where the homepage points is
    // remembered as a reference before the rows go.
    $homepageBefore = '';

    if ($package['has_content'] && !isset($package['settings']['homepage'])) {
        $homepageBefore = content_package_ref_of((int) (load_settings()['homepage_id'] ?? 0));
    }

    $pdo->beginTransaction();

    try {
        if ($package['has_content']) {
            // Replace, not merge: a package is a site's content, and half of
            // two sites is nobody's site. Versions and relationships go with
            // it, or they would point at rows that no longer exist.
            $pdo->exec("DELETE FROM content_versions");
            $pdo->exec("DELETE FROM taxonomy_term_relationships");
            $pdo->exec("DELETE FROM content");
            $pdo->exec("DELETE FROM taxonomy");
            $pdo->exec("DELETE FROM menus");

            $ids = [];

            $insert = $pdo->prepare("
                INSERT INTO content (type, slug, parent_id, title, status, layout, header, footer, meta, body,
                                     published_at, scheduled_at, created_at, updated_at)
                VALUES (:type, :slug, NULL, :title, :status, :layout, :header, :footer, :meta, :body,
                        :published_at, :scheduled_at, :created_at, :updated_at)
            ");

            foreach ($package['content'] as $item) {
                $path  = trim((string) $item['path'], '/');
                $parts = explode('/', $path);
                $slug  = (string) end($parts);

                $insert->execute([
                    'type'         => (string) $item['type'],
                    'slug'         => $slug,
                    'title'        => (string) ($item['title'] ?? $slug),
                    'status'       => (string) ($item['status'] ?? 'draft'),
                    'layout'       => (string) ($item['layout'] ?? ''),
                    'header'       => (string) ($item['header'] ?? ''),
                    'footer'       => (string) ($item['footer'] ?? ''),
                    'meta'         => json_encode($item['meta'] ?? [], JSON_UNESCAPED_SLASHES),
                    'body'         => json_encode($item['body'] ?? [], JSON_UNESCAPED_SLASHES),
                    'published_at' => isset($item['published_at']) ? (int) $item['published_at'] : null,
                    'scheduled_at' => isset($item['scheduled_at']) ? (int) $item['scheduled_at'] : null,
                    'created_at'   => isset($item['created_at']) ? (int) $item['created_at'] : $now,
                    'updated_at'   => isset($item['updated_at']) ? (int) $item['updated_at'] : $now,
                ]);

                $ids[(string) $item['type'] . ':' . $path] = (int) $pdo->lastInsertId();
                $summary['content']++;
            }

            // Parents second: every row exists by now.
            $setParent = $pdo->prepare("UPDATE content SET parent_id = :parent WHERE id = :id");

            foreach ($package['content'] as $item) {
                $parent = trim((string) ($item['parent'] ?? ''));

                if ($parent === '') {
                    continue;
                }

                $ref = (string) $item['type'] . ':' . trim((string) $item['path'], '/');

                if (isset($ids[$parent], $ids[$ref])) {
                    $setParent->execute(['parent' => $ids[$parent], 'id' => $ids[$ref]]);
                }
            }

            // Taxonomy terms, then the links between them and the content.
            $termIds = [];

            $insertTerm = $pdo->prepare("
                INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, description, created_at, updated_at)
                VALUES (:taxonomy_type, :content_type, :name, :slug, :description, :now, :now)
            ");

            foreach ($package['taxonomies'] as $term) {
                $insertTerm->execute([
                    'taxonomy_type' => (string) $term['type'],
                    'content_type'  => (string) $term['content_type'],
                    'name'          => (string) ($term['name'] ?? $term['slug']),
                    'slug'          => (string) $term['slug'],
                    'description'   => (string) ($term['description'] ?? ''),
                    'now'           => $now,
                ]);

                $termIds[(string) $term['type'] . ':' . (string) $term['content_type'] . ':' . (string) $term['slug']]
                    = (int) $pdo->lastInsertId();
                $summary['taxonomies']++;
            }

            $insertLink = $pdo->prepare("
                INSERT OR IGNORE INTO taxonomy_term_relationships (content_type, content_id, taxonomy_id)
                VALUES (:content_type, :content_id, :taxonomy_id)
            ");

            foreach ($package['taxonomies'] as $term) {
                $termKey = (string) $term['type'] . ':' . (string) $term['content_type'] . ':' . (string) $term['slug'];

                foreach ((array) ($term['content'] ?? []) as $ref) {
                    if (!isset($termIds[$termKey], $ids[(string) $ref])) {
                        continue;
                    }

                    $insertLink->execute([
                        'content_type' => (string) $term['content_type'],
                        'content_id'   => $ids[(string) $ref],
                        'taxonomy_id'  => $termIds[$termKey],
                    ]);
                    $summary['links']++;
                }
            }

            $insertMenu = $pdo->prepare("
                INSERT INTO menus (label, slug, items, updated_at) VALUES (:label, :slug, :items, :now)
            ");

            foreach ($package['menus'] as $menu) {
                $items = is_array($menu['items'] ?? null) ? $menu['items'] : [];

                $insertMenu->execute([
                    'label' => (string) $menu['label'],
                    'slug'  => (string) $menu['slug'],
                    // Content is in place by now, so menu links can point at this
                    // install's rows instead of the package's slugs alone.
                    'items' => json_encode(menu_items_attach_ids($items), JSON_UNESCAPED_SLASHES),
                    'now'   => $now,
                ]);
                $summary['menus']++;
            }
        }

        // The homepage the site already had, re-matched by path: importing the
        // same content back must not leave '/' serving the 404 page.
        if ($homepageBefore !== '') {
            if (isset($ids[$homepageBefore])) {
                save_settings(['homepage_id' => $ids[$homepageBefore]]);
                $summary['homepage'] = $homepageBefore;
            } else {
                // Nothing here matches it any more, so the setting is cleared
                // rather than left pointing at a row that is gone.
                $pdo->prepare("DELETE FROM settings WHERE `key` = 'homepage_id'")->execute();
                settings_cache_clear();
                $summary['warnings'][] = "The homepage '{$homepageBefore}' is not in this package, so the homepage is unset. Choose one in Settings.";
            }
        }

        if ($package['has_settings']) {
            $values = [];

            foreach ($package['settings'] as $key => $value) {
                if ($key === 'homepage') {
                    continue;
                }

                $values[(string) $key] = $value;
            }

            // The homepage is an id again by the time it is stored; resolve the
            // package's reference against what now exists.
            if (isset($package['settings']['homepage'])) {
                $ref   = (string) $package['settings']['homepage'];
                $found = $package['has_content'] && isset($ids[$ref]) ? $ids[$ref] : null;
                $row   = $found === null ? content_package_find_ref($ref) : null;

                if ($found === null && $row !== null) {
                    $found = (int) $row['id'];
                }

                if ($found !== null) {
                    $values['homepage_id'] = $found;
                    $summary['homepage']   = $ref;
                }
            }

            if ($values) {
                save_settings($values);
                $summary['settings'] = count($values);
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    search_reindex_all();
    invalidate_cache();

    if (($options['sitemap'] ?? true) !== false) {
        save_sitemap();
    }

    return $summary;
}

/*
|--------------------------------------------------------------------------
| Uploaded package files
|--------------------------------------------------------------------------
|
| An import is two steps — preview, then apply — and the file has to survive
| between them, so an upload is stashed under storage/imports/<token>/ for an
| hour. The theme's own demo files need none of this; they are read twice.
|
| The token is the only thing the browser sends back, so it is matched against
| a strict pattern before it is used as a path.
|--------------------------------------------------------------------------
*/

/**
 * One entry per uploaded file, from $_FILES['files'].
 *
 * @param array<string, mixed> $files
 * @return list<array{name: string, tmp_name: string, error: int, size: int}>
 */
function content_package_uploads(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }

    $names = is_array($files['name']) ? array_keys($files['name']) : [0];
    $out   = [];

    foreach ($names as $index) {
        $out[] = [
            'name'     => (string) (is_array($files['name']) ? $files['name'][$index] : $files['name']),
            'tmp_name' => (string) (is_array($files['tmp_name'] ?? null) ? ($files['tmp_name'][$index] ?? '') : ($files['tmp_name'] ?? '')),
            'error'    => (int) (is_array($files['error'] ?? null) ? ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE)),
            'size'     => (int) (is_array($files['size'] ?? null) ? ($files['size'][$index] ?? 0) : ($files['size'] ?? 0)),
        ];
    }

    return $out;
}

/**
 * Were any files actually chosen?
 *
 * @param list<array{error: int}> $uploads
 */
function content_package_uploads_present(array $uploads): bool
{
    foreach ($uploads as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }

    return false;
}

/**
 * Store uploaded package files for the apply step.
 *
 * @param list<array{name: string, tmp_name: string, error: int, size: int}> $uploads
 * @return array{token: string, count: int, errors: list<string>}
 */
function content_package_stash_store(array $uploads): array
{
    $token   = bin2hex(random_bytes(12));
    $root    = STORAGE_PATH . '/imports';
    $dir     = $root . '/' . $token;
    $errors  = [];
    $stored  = 0;

    foreach ($uploads as $index => $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = $file['name'] . ': the upload failed.';
            continue;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $errors[] = 'The upload could not be stored.';
            break;
        }

        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $index . '.json')) {
            $errors[] = $file['name'] . ': the upload could not be stored.';
            continue;
        }

        $stored++;
    }

    if ($stored === 0) {
        @rmdir($dir);
        $token = '';
    }

    return ['token' => $token, 'count' => $stored, 'errors' => $errors];
}

/**
 * Read stashed documents back.
 *
 * @return array{documents: list<array<string, mixed>>, errors: list<string>}
 */
function content_package_stash_read(string $token): array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
        return ['documents' => [], 'errors' => ['That import could not be found.']];
    }

    $dir = STORAGE_PATH . '/imports/' . $token;

    if (!is_dir($dir)) {
        return ['documents' => [], 'errors' => ['That import has expired. Upload the files again.']];
    }

    $documents = [];
    $errors    = [];

    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $parsed = content_package_parse((string) file_get_contents($file));

        if ($parsed['document'] === null) {
            $errors[] = basename($file) . ': ' . $parsed['error'];
            continue;
        }

        $documents[] = $parsed['document'];
    }

    return ['documents' => $documents, 'errors' => $errors];
}

/**
 * Throw a stash away.
 */
function content_package_stash_forget(string $token): void
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
        return;
    }

    foreach (glob(STORAGE_PATH . '/imports/' . $token . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir(STORAGE_PATH . '/imports/' . $token);
}

/**
 * Drop stashes nobody came back to.
 */
function content_package_stash_prune(int $olderThan = 3600): void
{
    foreach (glob(STORAGE_PATH . '/imports/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (filemtime($dir) >= time() - $olderThan) {
            continue;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}

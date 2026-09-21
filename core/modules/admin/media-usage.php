<?php
declare(strict_types=1);



/*
|--------------------------------------------------------------------------
| Media helpers
|--------------------------------------------------------------------------
| A media row stores a `base_path` (e.g. 2026/03/abc123) and a `formats_json`
| map of format => [relative variant paths]. Everything a theme needs is
| derived from those two, so no column is read speculatively.
|--------------------------------------------------------------------------
*/

/**
 * Where every media file is referenced, keyed by media id.
 *
 * One pass over content, settings and menus, so a page can show what a delete
 * would break without a query per file. A reference is found in two ways:
 *
 *  - **by id**, but only where an id is what gets stored: image-typed component
 *    props and the image settings. Matching any number anywhere would collide
 *    with ordinary values such as a WebP quality of 80 or a `limit` of 3.
 *  - **by path**, anywhere at all: a media URL pasted into rich text, a menu
 *    link, a setting. A base path is a unique `YYYY/MM/random` folder name, so
 *    finding it inside a longer string is unambiguous.
 *
 * Saved versions and form submissions are not scanned: they are history rather
 * than something the current site renders.
 *
 * @return array<int, list<string>> media id => labels of the places using it
 */
function media_usage_map(): array
{
    $usage = [];

    // Base path => id, for turning a path reference back into a media row.
    $idsByPath = [];

    foreach (db()->query("SELECT id, base_path FROM media") as $row) {
        $idsByPath[(string) $row['base_path']] = (int) $row['id'];
    }

    if ($idsByPath === []) {
        return $usage;
    }

    /**
     * Record a label against a media id, once.
     */
    $note = static function (int $id, string $label) use (&$usage): void {
        if (!in_array($label, $usage[$id] ?? [], true)) {
            $usage[$id][] = $label;
        }
    };

    /**
     * Record an id that is stored as-is. Only call this for fields where a
     * number really is a media id.
     */
    $noteId = static function (mixed $value, string $label) use ($note): void {
        if (is_scalar($value) && ctype_digit(trim((string) $value))) {
            $note((int) trim((string) $value), $label);
        }
    };

    /**
     * Record any media URLs or paths found inside a value of any shape.
     */
    $notePaths = static function (mixed $value, string $label) use ($idsByPath, $note, &$notePaths): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                $notePaths($item, $label);
            }

            return;
        }

        if (!is_scalar($value)) {
            return;
        }

        if (preg_match_all('#(\d{4}/\d{2}/[A-Za-z0-9]+)#', (string) $value, $matches)) {
            foreach (array_unique($matches[1]) as $candidate) {
                if (isset($idsByPath[$candidate])) {
                    $note($idsByPath[$candidate], $label);
                }
            }
        }
    };

    // Content: image props by id, anything at all by path.
    $contentTypes = theme_config()['content_types'] ?? [];

    $rows = db()->query("SELECT type, title, meta, body FROM content WHERE deleted_at IS NULL")->fetchAll();

    foreach ($rows as $row) {
        $type  = (string) $row['type'];
        $label = ($contentTypes[$type]['label'] ?? ucfirst($type)) . ' “' . (string) $row['title'] . '”';

        $meta = json_decode((string) $row['meta'], true);
        $meta = is_array($meta) ? $meta : [];
        $body = json_decode((string) $row['body'], true);
        $body = is_array($body) ? $body : [];

        // The meta keys a content type stores an image in: the images it
        // declares for the editor, the media fields among its other meta
        // fields, plus the older keys a layout reads directly.
        $imageMetaKeys = array_unique(array_merge(
            array_keys($contentTypes[$type]['images'] ?? []),
            array_keys(array_filter(
                content_meta_fields($contentTypes[$type] ?? []),
                static fn(array $field): bool => ($field['type'] ?? '') === 'media'
            )),
            ['thumbnail', 'image', 'author_image', 'gallery', 'og_image']
        ));

        foreach ($imageMetaKeys as $key) {
            foreach ((array) ($meta[$key] ?? []) as $value) {
                $noteId($value, $label);
            }
        }

        $notePaths($meta, $label);
        $notePaths($body, $label);

        // Component props: only a field the schema calls an image holds an id.
        $walk = static function (array $components) use (&$walk, $noteId, $label): void {
            foreach ($components as $component) {
                if (!is_array($component)) {
                    continue;
                }

                $name  = (string) ($component['type'] ?? '');
                $props = is_array($component['props'] ?? null) ? $component['props'] : [];

                if ($name !== '') {
                    $schema = content_component_definition($name)['schema'] ?? [];

                    foreach ($schema as $field => $rules) {
                        if (is_array($rules) && ($rules['type'] ?? '') === 'image') {
                            $noteId($props[$field] ?? null, $label);
                        }
                    }
                }

                $walk(is_array($component['children'] ?? null) ? $component['children'] : []);
            }
        };

        $walk($body);
    }

    // Settings: only the fields that hold an image can hold a media id.
    $settings = load_settings();

    foreach (['logo', 'favicon', 'default_og_image'] as $key) {
        $noteId($settings[$key] ?? null, 'Site settings (' . $key . ')');
    }

    $notePaths(array_filter($settings, 'is_scalar'), 'Site settings');

    // Menus only ever store URLs, so paths are the only thing to match.
    foreach (list_menus() as $menu) {
        $notePaths($menu['items'] ?? [], 'Menu “' . (string) $menu['label'] . '”');
    }

    return $usage;
}


/**
 * Every media base path mentioned anywhere in content, settings or menus.
 *
 * The same corpus media_usage_map() scans, but keyed by path rather than by
 * media id: a file whose row was removed has no id to key by, yet may still be
 * linked by URL in rich text. Saved versions and form submissions are history,
 * not what the site renders, so they are not scanned.
 *
 * @return list<string>
 */
function media_referenced_paths(): array
{
    $paths = [];

    $walk = static function (mixed $value) use (&$walk, &$paths): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                $walk($item);
            }

            return;
        }

        if (!is_scalar($value)) {
            return;
        }

        if (preg_match_all('#(\d{4}/\d{2}/[A-Za-z0-9]+)#', (string) $value, $matches)) {
            foreach ($matches[1] as $path) {
                $paths[$path] = true;
            }
        }
    };

    foreach (db()->query("SELECT meta, body FROM content WHERE deleted_at IS NULL") as $row) {
        // Decode first: JSON escapes the slashes in a URL, so a path regex run
        // over the raw column would never match.
        $walk(json_decode((string) $row['meta'], true));
        $walk(json_decode((string) $row['body'], true));
    }

    $walk(array_filter(load_settings(), 'is_scalar'));

    foreach (list_menus() as $menu) {
        $walk($menu['items'] ?? []);
    }

    return array_keys($paths);
}


/**
 * Compare the folders under storage/media with the media table.
 *
 * Both directions are reported: a folder no row owns, and a row whose folder is
 * gone — the case media_delete() answers 'invalid_path' for. Each entry carries
 * enough to render a report and to act on it.
 *
 * @return array{files: list<array{path: string, bytes: int}>, rows: list<array{id: int, base_path: string, original_name: string}>}
 */
function media_orphans(): array
{
    $root = realpath(STORAGE_PATH . '/media');

    // Without a readable media directory every row would look orphaned; report
    // nothing rather than a false alarm a repair would act on.
    if ($root === false || !is_dir($root)) {
        return ['files' => [], 'rows' => []];
    }

    $onDisk = [];

    foreach (glob($root . '/*/*/*', GLOB_ONLYDIR) ?: [] as $folder) {
        $relative = substr($folder, strlen($root) + 1);

        if (preg_match('#^\d{4}/\d{2}/[A-Za-z0-9]+$#', $relative) === 1) {
            $onDisk[$relative] = media_directory_size($folder);
        }
    }

    $rows   = db()->query("SELECT id, base_path, original_name FROM media")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byPath = [];

    foreach ($rows as $row) {
        $byPath[(string) $row['base_path']] = $row;
    }

    $files = [];

    foreach ($onDisk as $path => $bytes) {
        if (!isset($byPath[$path])) {
            $files[] = ['path' => $path, 'bytes' => $bytes];
        }
    }

    $missing = [];

    foreach ($rows as $row) {
        $path = (string) $row['base_path'];

        if (!isset($onDisk[$path])) {
            $missing[] = [
                'id'            => (int) $row['id'],
                'base_path'     => $path,
                'original_name' => (string) $row['original_name'],
            ];
        }
    }

    usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
    usort($missing, static fn(array $a, array $b): int => strcmp($a['base_path'], $b['base_path']));

    return ['files' => $files, 'rows' => $missing];
}


/**
 * Delete orphaned media folders: no row and no path reference anywhere.
 *
 * @param list<string> $paths
 * @return int folders removed
 */
function media_delete_orphans(array $paths): int
{
    $root = realpath(STORAGE_PATH . '/media');

    if ($root === false) {
        return 0;
    }

    $referenced = array_flip(media_referenced_paths());
    $known      = [];

    foreach (db()->query("SELECT base_path FROM media") as $row) {
        $known[(string) $row['base_path']] = true;
    }

    $removed = 0;

    foreach ($paths as $path) {
        $path = (string) $path;

        if (preg_match('#^\d{4}/\d{2}/[A-Za-z0-9]+$#', $path) !== 1
            || isset($known[$path])
            || isset($referenced[$path])) {
            continue;
        }

        $folder = realpath($root . '/' . $path);

        if ($folder === false || !str_starts_with($folder, $root . '/')) {
            continue;
        }

        delete_media_directory($folder);

        // The uploader creates YYYY/MM folders; remove them once they are empty.
        $dir = dirname($folder);
        while ($dir !== $root && is_dir($dir) && count(scandir($dir)) === 2) {
            @rmdir($dir);
            $dir = dirname($dir);
        }

        $removed++;
    }

    return $removed;
}


/**
 * Delete media rows whose folder is gone.
 *
 * This is the class media_delete() cannot remove: it reports 'invalid_path' and
 * leaves the row in the library.
 *
 * @param list<int> $ids
 * @return int rows removed
 */
function media_delete_missing_rows(array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));

    if ($ids === []) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("DELETE FROM media WHERE id IN ({$placeholders})");
    $stmt->execute($ids);

    return $stmt->rowCount();
}

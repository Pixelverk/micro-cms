<?php
declare(strict_types=1);



/**
 * Resolve a media id or an absolute URL to a URL.
 *
 * The shape every image setting accepts. Callers that need an absolute URL
 * (Open Graph, JSON-LD) pass the result through absolute_url(). Anything
 * else — a filename, a stray value — is not an image and resolves to nothing;
 * render_image() is the entry point that shows the CMS placeholder for one.
 */
function resolve_image_value(string $value, ?int $width = null): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (ctype_digit($value)) {
        return media_url((int) $value, $width);
    }

    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    return '';
}


/**
 * Total bytes in a directory tree.
 */
function media_directory_size(string $dir): int
{
    $bytes = 0;

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path)) {
            $bytes += media_directory_size($path);
        } elseif (is_file($path)) {
            $bytes += (int) filesize($path);
        }
    }

    return $bytes;
}


/**
 * Delete one media row and the folder holding its files.
 *
 * Returns 'deleted', 'not_found' or 'invalid_path'. The folder goes first: a
 * row left behind would render a broken image on the site, while a folder left
 * behind is only wasted bytes. The path is resolved against the media directory
 * so a crafted base_path can never reach outside storage.
 */
function media_delete(int $id): string
{
    $pdo = db();

    $stmt = $pdo->prepare("SELECT base_path FROM media WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $basePath = $stmt->fetchColumn();

    if ($basePath === false) {
        return 'not_found';
    }

    $mediaRoot = realpath(STORAGE_PATH . '/media');
    $folder    = $mediaRoot === false ? false : realpath($mediaRoot . '/' . $basePath);

    if ($folder === false || !str_starts_with($folder, $mediaRoot)) {
        return 'invalid_path';
    }

    delete_media_directory($folder);

    // The uploader creates YYYY/MM folders; remove them once they are empty.
    $dir = dirname($folder);
    while ($dir !== $mediaRoot && is_dir($dir) && count(scandir($dir)) === 2) {
        @rmdir($dir);
        $dir = dirname($dir);
    }

    $pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$id]);

    return 'deleted';
}


/**
 * Recursively delete a media folder and everything inside it.
 *
 * Used when media is removed and when a replacement upload supersedes an
 * existing file's folder. Callers validate the path first.
 */
function delete_media_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path)) {
            delete_media_directory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}


/**
 * Load a media row once per request.
 */
function media_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    static $cache = [];

    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    $stmt = db()->prepare("SELECT * FROM media WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);

    return $cache[$id] = ($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
}


/**
 * Decoded formats map: ['webp' => ['path1', ...], 'jpg' => [...]].
 *
 * @return array<string, list<string>>
 */
function media_formats(array $media): array
{
    $formats = json_decode((string) ($media['formats_json'] ?? ''), true);

    return is_array($formats) ? $formats : [];
}


/**
 * Public URL for a media row, optionally the variant nearest a width.
 *
 * Non-image media (PDF, MP4, …) only has the original, which formats_json
 * still records, so this works for every media type.
 */
function media_url(int $id, ?int $width = null, ?string $format = null): string
{
    $media = media_by_id($id);

    if (!$media) {
        return '';
    }

    $formats = media_formats($media);

    $chosen = null;

    if ($format !== null && !empty($formats[$format])) {
        $chosen = $formats[$format];
    } else {
        foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $candidate) {
            if (!empty($formats[$candidate])) {
                $chosen = $formats[$candidate];
                break;
            }
        }
    }

    // Fall back to whatever the first format recorded (covers pdf, mp4, …).
    if ($chosen === null) {
        foreach ($formats as $paths) {
            if (!empty($paths)) {
                $chosen = $paths;
                break;
            }
        }
    }

    // A row with no recorded variants at all: rebuild the original path.
    if ($chosen === null) {
        $original = (string) ($media['original_name'] ?? '');

        if ($original === '' || empty($media['base_path'])) {
            return '';
        }

        $base = sanitize_slug(pathinfo($original, PATHINFO_FILENAME));
        $ext  = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        return $ext === '' ? '' : url("media/{$media['base_path']}/{$base}.{$ext}");
    }

    // Pick the variant whose width token is closest to the request.
    $best = null;
    $bestDistance = PHP_INT_MAX;

    foreach ($chosen as $path) {
        $variantWidth = null;

        if (preg_match('/-(\d+)\.[a-z0-9]+$/i', $path, $matches)) {
            $variantWidth = (int) $matches[1];
        }

        if ($width === null || $variantWidth === null) {
            $best = $best ?? $path;
            continue;
        }

        $distance = abs($variantWidth - $width);

        if ($distance < $bestDistance) {
            $best = $path;
            $bestDistance = $distance;
        }
    }

    $best = $best ?? reset($chosen);

    return $best ? url('media/' . $best) : '';
}


/**
 * Is this media row renderable as an image?
 */
function media_is_image(array $media): bool
{
    return str_starts_with((string) ($media['mime_type'] ?? ''), 'image/');
}

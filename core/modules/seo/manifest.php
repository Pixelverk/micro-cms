<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| App icons, browser chrome and the web manifest
|--------------------------------------------------------------------------
|
| What an installed site shows: the icons, the colour the browser tints its
| chrome with, and the manifest that names them. The icons come from what the
| site already has — a raster logo or favicon from Settings, then the icons the
| theme declares — because a generated icon is a guess at somebody's branding.
| A manifest with no icons is still a valid manifest.
*/

/** MIME types an app icon may use. */
const SEO_ICON_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

/**
 * Every icon the site can offer, biggest first.
 *
 * @return list<array{src: string, width: int, height: int, type: string}>
 */
function seo_icon_candidates(): array
{
    $candidates = [];

    // A logo or favicon the site owner uploaded or pointed at.
    foreach (['logo', 'favicon'] as $setting) {
        $value = trim((string) get_setting($setting, ''));

        if ($value === '') {
            continue;
        }

        if (ctype_digit($value)) {
            $candidates = array_merge($candidates, seo_media_icon_candidates((int) $value));
            continue;
        }

        $icon = seo_theme_icon($value);

        if ($icon !== null) {
            $candidates[] = $icon;
        }
    }

    // Then the theme's own app icons, declared as icons.app in theme.php.
    foreach ((array) (theme_config()['icons']['app'] ?? []) as $file) {
        $icon = is_string($file) ? seo_theme_icon($file) : null;

        if ($icon !== null) {
            $candidates[] = $icon;
        }
    }

    usort($candidates, static fn(array $a, array $b): int => $b['width'] <=> $a['width']);

    // One entry per file: a variant is stored in several formats.
    $seen   = [];
    $unique = [];

    foreach ($candidates as $icon) {
        if (isset($seen[$icon['src']])) {
            continue;
        }

        $seen[$icon['src']] = true;
        $unique[]           = $icon;
    }

    return $unique;
}


/**
 * A media row's variants, as icon candidates.
 *
 * @return list<array{src: string, width: int, height: int, type: string}>
 */
function seo_media_icon_candidates(int $id): array
{
    $media = media_by_id($id);

    if (!$media) {
        return [];
    }

    $formats = media_formats($media);
    $sizes   = json_decode((string) ($media['sizes_json'] ?? ''), true);
    $sizes   = is_array($sizes) ? $sizes : [];
    $icons   = [];

    // PNG first: it is the format every installer accepts.
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $format) {
        foreach ((array) ($formats[$format] ?? []) as $path) {
            $path = (string) $path;

            if ($path === '') {
                continue;
            }

            // The width is a suffix on the filename; the original carries none.
            $width = preg_match('/-(\d+)\.[a-z0-9]+$/i', $path, $matches) ? (int) $matches[1] : (int) ($media['width'] ?? 0);
            $size  = $sizes[(string) $width] ?? $sizes[$width] ?? null;

            $pixels = (int) ($size['width'] ?? $width);
            $height = (int) ($size['height'] ?? $pixels);

            if ($pixels <= 0) {
                continue;
            }

            $icons[] = [
                'src'    => url('media/' . $path),
                'width'  => $pixels,
                'height' => $height,
                'type'   => 'image/' . ($format === 'jpg' ? 'jpeg' : $format),
            ];
        }
    }

    return $icons;
}


/**
 * A theme image as an icon, or null when it is not a raster file we can size.
 */
function seo_theme_icon(string $value): ?array
{
    $value = trim($value);

    // A remote URL would have to be fetched to be sized, which is not worth it
    // for an icon.
    if ($value === '' || preg_match('#^https?://#i', $value) || str_starts_with($value, '//')) {
        return null;
    }

    $file = CMS_PATH . '/theme/assets/' . ltrim($value, '/');

    if (!is_file($file)) {
        return null;
    }

    $info = @getimagesize($file);

    if (!$info || empty($info['mime']) || !in_array($info['mime'], SEO_ICON_TYPES, true)) {
        return null;
    }

    return [
        'src'    => asset($value),
        'width'  => (int) $info[0],
        'height' => (int) $info[1],
        'type'   => (string) $info['mime'],
    ];
}


/**
 * The icon nearest a target size, preferring one big enough to scale down.
 *
 * @param list<array{src: string, width: int, height: int, type: string}> $candidates Biggest first.
 * @return array{src: string, width: int, height: int, type: string}|null
 */
function seo_icon_at(array $candidates, int $target): ?array
{
    $above = null;
    $below = null;

    foreach ($candidates as $icon) {
        if ($icon['width'] >= $target) {
            $above = $icon; // Keeps the last, which is the smallest one big enough.
        } else {
            $below ??= $icon; // The first, which is the biggest one available.
        }
    }

    return $above ?? $below;
}


/**
 * The icons a browser should install, one per target size.
 *
 * @return list<array{src: string, sizes: string, type: string}>
 */
function seo_app_icons(): array
{
    $candidates = seo_icon_candidates();
    $icons      = [];

    foreach ([192, 512] as $target) {
        $icon = seo_icon_at($candidates, $target);

        if ($icon !== null) {
            $icons[] = [
                'src'   => $icon['src'],
                'sizes' => $icon['width'] . 'x' . $icon['height'],
                'type'  => $icon['type'],
            ];
        }
    }

    return $icons;
}


/**
 * The colour the browser tints its chrome with: Settings, then the theme.
 *
 * A theme may also declare `meta.theme_color_dark`, which is emitted as its own
 * tag for a visitor whose system is in dark mode.
 */
function seo_theme_color(): string
{
    $setting = trim((string) (load_settings()['theme_color'] ?? ''));

    if ($setting !== '') {
        return $setting;
    }

    return trim((string) (theme_config()['meta']['theme_color'] ?? ''));
}


/**
 * The manifest for the site being served.
 *
 * @return array<string, mixed>
 */
function seo_manifest(): array
{
    $settings  = load_settings();
    $title     = trim((string) ($settings['site_title'] ?? 'My Site'));
    $shortName = mb_substr($title, 0, 15);

    $manifest = [
        'name'             => $title,
        'short_name'       => $shortName !== '' ? $shortName : 'Site',
        'start_url'        => url('/'),
        'scope'            => url('/'),
        'display'          => 'standalone',
        'background_color' => trim((string) (theme_config()['meta']['background_color'] ?? '')) ?: '#ffffff',
    ];

    $description = trim((string) ($settings['site_description'] ?? ''));

    if ($description !== '') {
        $manifest['description'] = $description;
    }

    $theme = seo_theme_color();

    if ($theme !== '') {
        $manifest['theme_color'] = $theme;
    }

    $icons = seo_app_icons();

    if ($icons) {
        $manifest['icons'] = $icons;
    }

    return $manifest;
}


function seo_manifest_json(): string
{
    return (string) json_encode(
        seo_manifest(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}


/**
 * The head tags for installing the site and colouring the browser chrome.
 */
function seo_app_head_tags(): string
{
    $head = "<link rel='manifest' href='" . e(url('site.webmanifest')) . "'>\n";

    $apple = seo_icon_at(seo_icon_candidates(), 180);

    if ($apple !== null) {
        $head .= "<link rel='apple-touch-icon' sizes='" . e($apple['width'] . 'x' . $apple['height']) . "' href='" . e($apple['src']) . "'>\n";
    }

    $theme = seo_theme_color();

    if ($theme !== '') {
        $head .= "<meta name='theme-color' content='" . e($theme) . "'>\n";
    }

    $dark = trim((string) (theme_config()['meta']['theme_color_dark'] ?? ''));

    if ($dark !== '') {
        $head .= "<meta name='theme-color' media='(prefers-color-scheme: dark)' content='" . e($dark) . "'>\n";
    }

    return $head;
}

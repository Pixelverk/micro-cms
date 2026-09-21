<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SEO metadata
|--------------------------------------------------------------------------
|
| One place decides what goes in <head>: canonical URL, robots, description,
| Open Graph / Twitter tags and optional JSON-LD. Editors supply values per
| content item; anything they leave blank falls back to sensible defaults.
|
| All values live in the content `meta` JSON, so no schema change was needed.
|
| Recognised keys:
|   seo_title, description, canonical, robots_extra
|   og_title, og_description, og_image, og_type
|   twitter_card, twitter_site
|   excerpt, thumbnail, author, author_image   (theme/presentation keys)
*/

/**
 * The site's canonical origin, e.g. https://example.com (no trailing slash).
 *
 * Prefers the `site_url` setting, then the CMS `url` config, then the request.
 */
function seo_site_url(): string
{
    static $cached = null;

    if (is_string($cached)) {
        return $cached;
    }

    $configured = trim((string) get_setting('site_url', ''));

    if ($configured === '') {
        // config('url') is a path prefix for subfolder installs, not an origin,
        // so it is only usable when it is already absolute.
        $configUrl = trim((string) config('url', ''));

        if (preg_match('#^https?://#i', $configUrl)) {
            $configured = $configUrl;
        }
    }

    if ($configured === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https'
            : 'http';

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Never trust a Host header containing anything but a hostname/port.
        if (!preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', $host)) {
            $host = 'localhost';
        }

        $configured = $scheme . '://' . $host;
    }

    return $cached = rtrim($configured, '/');
}

/**
 * Absolute form of a site-relative URL (or pass an absolute one through).
 */
function seo_absolute_url(string $url): string
{
    if ($url === '') {
        return seo_site_url() . '/';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    return seo_site_url() . '/' . ltrim($url, '/');
}

/**
 * The canonical path for the page being rendered.
 *
 * Taxonomy archives come from their term slug; content uses its own URL from
 * the router (which already accounts for prefixes and nesting).
 */
function seo_canonical_path(array $page): string
{
    $settings = load_settings();

    // Homepage renders at the site root.
    $homepageId = $settings['homepage_id'] ?? null;

    if (!empty($page['id']) && $homepageId && (int) $page['id'] === (int) $homepageId) {
        return '/';
    }

    if (!empty($page['taxonomy']['slug']) && !empty($page['taxonomy']['taxonomy_type'])) {
        return '/' . $page['taxonomy']['taxonomy_type'] . '/' . $page['taxonomy']['slug'] . seo_page_suffix();
    }

    return '/' . trim((string) ($page['path'] ?? $page['slug'] ?? ''), '/') . seo_page_suffix();
}

/**
 * The `?page=N` part of a canonical URL, or '' on the first page.
 *
 * A paged listing is its own canonical URL: pointing pages 2+ at page 1 would
 * tell search engines to drop them from the index. The first page keeps its
 * clean URL.
 */
function seo_page_suffix(): string
{
    $current = pagination_current_page();

    return $current > 1 ? '?page=' . $current : '';
}

/**
 * Resolve every SEO value for a page, applying defaults and fallbacks.
 *
 * @return array<string, string>
 */
function seo_metadata(array $page): array
{
    $meta      = is_array($page['meta'] ?? null) ? $page['meta'] : [];
    $settings  = load_settings();

    $siteTitle = trim((string) ($settings['site_title'] ?? 'My Site'));
    $pageTitle = trim((string) ($page['title'] ?? ''));

    $suffix = trim((string) ($settings['seo_title_suffix'] ?? ''));

    // Title: item override, else "Page - Site" (or just the site on the home page).
    $title = trim((string) ($meta['seo_title'] ?? ''));

    if ($title === '') {
        if ($suffix !== '') {
            $title = $pageTitle !== '' ? $pageTitle . ' ' . $suffix : $suffix;
        } else {
            $isHome = empty($page['slug']) || (($settings['homepage_slug'] ?? null) === ($page['slug'] ?? null));
            $title  = $isHome || $pageTitle === '' ? $siteTitle : $pageTitle . ' - ' . $siteTitle;
        }
    }

    $description = trim((string) ($meta['description'] ?? $meta['excerpt'] ?? ''));

    // A page with no description of its own borrows the site description.
    if ($description === '') {
        $description = trim((string) ($settings['site_description'] ?? ''));
    }

    $canonical = trim((string) ($meta['canonical'] ?? ''));

    if ($canonical === '') {
        $canonical = seo_canonical_path($page);
    }

    // og:type by content type, with an override.
    $ogType = trim((string) ($meta['og_type'] ?? ''));

    if ($ogType === '') {
        $ogType = ($page['type'] ?? '') === 'blog_post' ? 'article' : 'website';
    }

    $ogImage = trim((string) ($meta['og_image'] ?? ''));

    if ($ogImage === '') {
        // A media id (integer) or a theme image filename may be configured.
        $defaultImage = trim((string) ($settings['default_og_image'] ?? ''));
        $ogImage = $defaultImage;
    }

    // An article says when it was published and who wrote it. The page array
    // already carries both, so this needs no extra query.
    $isArticle   = $ogType === 'article';
    $publishedAt = (int) ($page['published_at'] ?? 0);
    $updatedAt   = (int) ($page['updated_at'] ?? 0);
    $categories  = is_array($page['categories'] ?? null) ? $page['categories'] : [];
    $tags        = is_array($page['tags'] ?? null) ? $page['tags'] : [];

    $section    = '';
    $articleTags = [];

    if ($isArticle) {
        $section = trim((string) ($categories[0]['name'] ?? ''));

        foreach ($tags as $tag) {
            $name = trim((string) ($tag['name'] ?? ''));

            if ($name !== '') {
                $articleTags[] = $name;
            }
        }
    }

    return [
        'title'             => $title,
        'description'       => $description,
        'canonical'         => seo_absolute_url($canonical),
        'canonical_override'=> trim((string) ($meta['canonical'] ?? '')) !== '' ? '1' : '',
        'robots'            => seo_robots($page),
        'og_type'           => $ogType,
        'og_title'          => trim((string) ($meta['og_title'] ?? '')) ?: $title,
        'og_description'    => trim((string) ($meta['og_description'] ?? '')) ?: $description,
        'og_image'          => seo_resolve_image($ogImage),
        'twitter_card'      => trim((string) ($meta['twitter_card'] ?? '')) ?: 'summary_large_image',
        'twitter_site'      => trim((string) ($meta['twitter_site'] ?? $settings['twitter_site'] ?? '')),
        // The creator is the person; the site handle is the fallback.
        'twitter_creator'   => trim((string) ($meta['twitter_creator'] ?? $settings['twitter_site'] ?? '')),
        'site_name'         => $siteTitle,
        'author'            => trim((string) ($meta['author'] ?? '')),
        'article_section'   => $section,
        'article_tags'      => $articleTags,
        'published_time'    => $isArticle && $publishedAt > 0 ? gmdate('c', $publishedAt) : '',
        'modified_time'     => $isArticle && $updatedAt > 0 ? gmdate('c', $updatedAt) : '',
    ];
}

/**
 * Robots directive for a page.
 *
 * Unpublished states are always noindex — an editor cannot override that,
 * because a draft must never be indexed even if it is previewed publicly.
 */
function seo_robots(array $page): string
{
    $status = (string) ($page['status'] ?? 'published');

    if (in_array($status, ['draft', 'scheduled', 'archived'], true)) {
        return 'noindex, nofollow';
    }

    // A not-found response must never be indexed. Its links are still worth
    // following, so this is noindex rather than nofollow.
    if ($status === '404') {
        return 'noindex, follow';
    }

    $meta = is_array($page['meta'] ?? null) ? $page['meta'] : [];
    $extra = trim((string) ($meta['robots_extra'] ?? ''));

    // Allow "noindex" style overrides, but keep the value safe.
    if ($extra !== '' && preg_match('/^[a-z]+(,\s*[a-z]+)*$/', $extra)) {
        return $extra;
    }

    return 'index, follow';
}

/**
 * Turn an og:image value into an absolute URL. Accepts a media id, an
 * absolute URL, or a theme image filename.
 */
function seo_resolve_image(string $value): string
{
    $url = resolve_image_value($value);

    return $url === '' ? '' : seo_absolute_url($url);
}

/**
 * Render the SEO head block.
 */
function seo_head_tags(array $page): string
{
    $seo = seo_metadata($page);

    $head = '';

    $head .= "<title>" . e($seo['title']) . "</title>\n";

    if ($seo['description'] !== '') {
        $head .= "<meta name='description' content='" . e($seo['description']) . "'>\n";
    }

    $head .= "<link rel='canonical' href='" . e($seo['canonical']) . "'>\n";
    $head .= "<meta name='robots' content='" . e($seo['robots']) . "'>\n";

    // Open Graph
    $head .= "<meta property='og:site_name' content='" . e($seo['site_name']) . "'>\n";
    $head .= "<meta property='og:type' content='" . e($seo['og_type']) . "'>\n";
    $head .= "<meta property='og:title' content='" . e($seo['og_title']) . "'>\n";
    $head .= "<meta property='og:url' content='" . e($seo['canonical']) . "'>\n";

    if ($seo['og_description'] !== '') {
        $head .= "<meta property='og:description' content='" . e($seo['og_description']) . "'>\n";
    }

    if ($seo['og_image'] !== '') {
        $head .= "<meta property='og:image' content='" . e($seo['og_image']) . "'>\n";
    }

    // Article metadata, for anything a crawler should read as an article.
    if ($seo['og_type'] === 'article') {
        if ($seo['published_time'] !== '') {
            $head .= "<meta property='article:published_time' content='" . e($seo['published_time']) . "'>\n";
        }

        if ($seo['modified_time'] !== '') {
            $head .= "<meta property='article:modified_time' content='" . e($seo['modified_time']) . "'>\n";
        }

        if ($seo['author'] !== '') {
            $head .= "<meta property='article:author' content='" . e($seo['author']) . "'>\n";
        }

        if ($seo['article_section'] !== '') {
            $head .= "<meta property='article:section' content='" . e($seo['article_section']) . "'>\n";
        }

        foreach ($seo['article_tags'] as $tag) {
            $head .= "<meta property='article:tag' content='" . e($tag) . "'>\n";
        }
    }

    // Twitter
    $head .= "<meta name='twitter:card' content='" . e($seo['twitter_card']) . "'>\n";
    $head .= "<meta name='twitter:title' content='" . e($seo['og_title']) . "'>\n";

    if ($seo['og_description'] !== '') {
        $head .= "<meta name='twitter:description' content='" . e($seo['og_description']) . "'>\n";
    }

    if ($seo['og_image'] !== '') {
        $head .= "<meta name='twitter:image' content='" . e($seo['og_image']) . "'>\n";
    }

    if ($seo['twitter_site'] !== '') {
        $head .= "<meta name='twitter:site' content='" . e($seo['twitter_site']) . "'>\n";
    }

    if ($seo['twitter_creator'] !== '') {
        $head .= "<meta name='twitter:creator' content='" . e($seo['twitter_creator']) . "'>\n";
    }

    return $head;
}

/**
 * Optional JSON-LD structured data for a page.
 *
 * Only emitted when the theme enables it (`'schema' => true` in theme.php).
 */
function seo_json_ld(array $page): string
{
    $theme = theme_config();

    if (empty($theme['schema'])) {
        return '';
    }

    $seo  = seo_metadata($page);
    $type = ($page['type'] ?? '') === 'blog_post' ? 'Article' : 'WebPage';

    $data = [
        '@context' => 'https://schema.org',
        '@type'    => $type,
        'name'     => $seo['title'],
        'url'      => $seo['canonical'],
    ];

    if ($seo['description'] !== '') {
        $data['description'] = $seo['description'];
    }

    if ($seo['og_image'] !== '') {
        $data['image'] = $seo['og_image'];
    }

    $meta = is_array($page['meta'] ?? null) ? $page['meta'] : [];

    if ($type === 'Article') {
        if (!empty($meta['author'])) {
            $data['author'] = ['@type' => 'Person', 'name' => (string) $meta['author']];
        }

        if (!empty($page['published_at'])) {
            $data['datePublished'] = gmdate('c', (int) $page['published_at']);
        }

        if (!empty($page['updated_at'])) {
            $data['dateModified'] = gmdate('c', (int) $page['updated_at']);
        }
    }

    $out = "<script type='application/ld+json'>"
        . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        . "</script>\n";

    // The homepage also identifies the organisation behind the site, which is
    // what a brochure site's structured data is mostly for.
    $settings = load_settings();

    if (!empty($settings['homepage_id']) && (int) ($page['id'] ?? 0) === (int) $settings['homepage_id']) {
        $organization = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $seo['site_name'],
            'url'      => seo_site_url() . '/',
        ];

        $out .= "<script type='application/ld+json'>"
            . json_encode($organization, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            . "</script>\n";
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| Editor input
|--------------------------------------------------------------------------
*/

/**
 * Every SEO/social field the editor can set, with its input type and limits.
 *
 * @return array<string, array{type: string, label: string, max?: int, help?: string}>
 */
function seo_editable_fields(): array
{
    return [
        'seo_title'       => ['type' => 'text',     'label' => 'SEO title',        'max' => 70,  'help' => 'Shown in the browser tab and search results. Falls back to the title.', 'group' => 'search'],
        'description'     => ['type' => 'textarea', 'label' => 'Meta description', 'max' => 160, 'help' => 'Roughly 160 characters.', 'group' => 'search'],
        'canonical'       => ['type' => 'text',     'label' => 'Canonical URL',    'help' => 'Only needed when this content duplicates another URL.', 'group' => 'search'],
        'og_title'        => ['type' => 'text',     'label' => 'Social title',     'max' => 70, 'group' => 'social'],
        'og_description'  => ['type' => 'textarea', 'label' => 'Social description', 'max' => 200, 'group' => 'social'],
        'og_image'        => ['type' => 'media',    'label' => 'Social image',     'help' => 'Used for Open Graph and Twitter cards.', 'group' => 'social'],
        'author'          => ['type' => 'text',     'label' => 'Author',           'max' => 70, 'help' => 'Shown on the post and in article metadata.', 'group' => 'social'],
        'twitter_site'    => ['type' => 'text',     'label' => 'Twitter/X handle', 'max' => 30, 'help' => 'Optional, e.g. @example. Falls back to the site handle in Settings.', 'group' => 'social'],
        'twitter_creator' => ['type' => 'text',     'label' => 'Twitter/X creator', 'max' => 30, 'help' => 'The writer, when that is not the site account. e.g. @example.', 'group' => 'social'],
    ];
}

/**
 * The editor's fields grouped into the cards it shows, in order.
 *
 * `group` on a field is which card it belongs to; this is the one place that
 * decides what those cards are called and how they are ordered. A field with
 * no group — or one this does not know — joins the last card, so a field in
 * seo_editable_fields() can never be silently dropped from the form.
 *
 * @return list<array{key: string, label: string, fields: array<string, array<string, mixed>>}>
 */
function seo_editable_field_groups(): array
{
    $grouped = [
        'search' => ['key' => 'search', 'label' => 'Search', 'fields' => []],
        'social' => ['key' => 'social', 'label' => 'Social', 'fields' => []],
    ];

    foreach (seo_editable_fields() as $name => $field) {
        $group = (string) ($field['group'] ?? '');

        if (!isset($grouped[$group])) {
            $group = array_key_last($grouped);
        }

        $grouped[$group]['fields'][$name] = $field;
    }

    return array_values($grouped);
}

/**
 * Normalise the posted SEO fields into the content meta array.
 *
 * @param array<string, mixed> $post  Typically $_POST
 * @param array<string, mixed> $meta  Existing meta (updated in place and returned)
 * @return array<string, mixed>
 */
function seo_collect_meta(array $post, array $meta): array
{
    foreach (seo_editable_fields() as $key => $field) {
        $value = $post['meta_' . $key] ?? null;

        // Fields the form did not submit are left untouched (e.g. partial saves).
        if ($value === null) {
            continue;
        }

        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            unset($meta[$key]);
            continue;
        }

        $max = (int) ($field['max'] ?? 0);

        if ($max > 0 && mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }

        $meta[$key] = $value;
    }

    return $meta;
}

/**
 * Canonical URLs must be absolute http(s); anything else is rejected.
 */
function seo_validate_canonical(string $value): bool
{
    if ($value === '') {
        return true;
    }

    if (!validate_url($value)) {
        return false;
    }

    // Same-origin only: a canonical pointing at a different domain is almost
    // always a mistake for a single-site CMS.
    $host = parse_url($value, PHP_URL_HOST);
    $siteHost = parse_url(seo_site_url(), PHP_URL_HOST);

    return $host !== null && $siteHost !== null && strcasecmp($host, $siteHost) === 0;
}

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
|
*/

/** Image types a browser accepts as an app icon. */
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

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
        'site_name'         => $siteTitle,
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
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (ctype_digit($value)) {
        $url = media_url((int) $value);

        return $url === '' ? '' : seo_absolute_url($url);
    }

    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    return seo_absolute_url(img($value));
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

    return "<script type='application/ld+json'>"
        . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        . "</script>\n";
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
        'seo_title'       => ['type' => 'text',     'label' => 'SEO title',        'max' => 70,  'help' => 'Shown in the browser tab and search results. Falls back to the title.'],
        'description'     => ['type' => 'textarea', 'label' => 'Meta description', 'max' => 160, 'help' => 'Roughly 160 characters.'],
        'canonical'       => ['type' => 'text',     'label' => 'Canonical URL',    'help' => 'Only needed when this content duplicates another URL.'],
        'robots_extra'    => ['type' => 'text',     'label' => 'Robots override',  'help' => 'Leave blank for "index, follow". Example: noindex, follow'],
        'og_title'        => ['type' => 'text',     'label' => 'Social title',     'max' => 70],
        'og_description'  => ['type' => 'textarea', 'label' => 'Social description', 'max' => 200],
        'og_image'        => ['type' => 'media',    'label' => 'Social image',     'help' => 'Used for Open Graph and Twitter cards.'],
        'twitter_site'    => ['type' => 'text',     'label' => 'Twitter/X handle', 'max' => 30, 'help' => 'Optional, e.g. @example.'],
    ];
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

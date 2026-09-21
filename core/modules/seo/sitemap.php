<?php
declare(strict_types=1);

/**
 * Generate sitemap XML from database content.
 *
 * Only published, due content is listed — drafts, scheduled and archived
 * items must never be advertised to crawlers.
 */
function generate_sitemap(): string
{
    $theme     = theme_config();
    $settings  = load_settings();
    $prefixes  = $settings['content_prefixes'] ?? [];
    $types     = array_keys($theme['content_types'] ?? []);
    $homepageId = $settings['homepage_id'] ?? null;

    // Canonical origin, from the site_url setting or the current request.
    $baseUrl = site_origin();

    $pdo  = db();
    $urls = [];
    $now  = time();

    foreach ($types as $type) {
        $prefix = $prefixes[$type] ?? '';

        $stmt = $pdo->prepare("
            SELECT id, slug, meta, updated_at, published_at
            FROM content
            WHERE type = :type
              AND status = 'published'
              AND published_at IS NOT NULL
              AND published_at <= :now
              AND deleted_at IS NULL
        ");
        $stmt->execute(['type' => $type, 'now' => $now]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

            // A per-page robots override of noindex means the editor does not
            // want this URL indexed, so it must not be advertised here either.
            $meta = json_decode((string) ($row['meta'] ?? ''), true);
            $robots = seo_robots(['status' => 'published', 'meta' => is_array($meta) ? $meta : []]);

            if (str_contains($robots, 'noindex')) {
                continue;
            }

            // Prefer published date
            $timestamp = $row['published_at'] ?: $row['updated_at'];
            $lastmod   = date('Y-m-d', (int)$timestamp);

            // Homepage handling: only hide path if this is the homepage
            if ($homepageId && (int)$row['id'] === (int)$homepageId) {
                $path = '';
            } else {
                $path = trim(($prefix ? "{$prefix}/" : '') . $row['slug'], '/');
            }

            $urls[] = [
                'loc'        => $path === '' ? $baseUrl . '/' : $baseUrl . '/' . $path . '/',
                'lastmod'    => $lastmod,
                'changefreq' => 'weekly',
                'priority'   => $type === 'page' ? '1.0' : '0.7',
            ];
        }
    }

    // Taxonomy archives: a declared taxonomy's terms that have at least one
    // published item. An empty archive is not advertised.
    foreach (theme_taxonomies() as $taxonomyName => $taxonomyConfig) {
        $prefix = trim((string) ($taxonomyConfig['url_prefix'] ?? ''), '/');

        if ($prefix === '') {
            continue;
        }

        $stmt = $pdo->prepare("
            SELECT t.slug, t.updated_at
            FROM taxonomy t
            WHERE t.taxonomy_type = :type
              AND EXISTS (
                  SELECT 1
                  FROM taxonomy_term_relationships r
                  INNER JOIN content c ON c.id = r.content_id AND c.type = r.content_type
                  WHERE r.taxonomy_id = t.id
                    AND c.status = 'published'
                    AND c.published_at IS NOT NULL
                    AND c.published_at <= :now
                    AND c.deleted_at IS NULL
              )
            ORDER BY t.slug
        ");
        $stmt->execute(['type' => $taxonomyName, 'now' => $now]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $term) {
            $urls[] = [
                'loc'        => $baseUrl . '/' . $prefix . '/' . $term['slug'] . '/',
                'lastmod'    => date('Y-m-d', (int) $term['updated_at']),
                'changefreq' => 'weekly',
                'priority'   => '0.5',
            ];
        }
    }

    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset/>');
    $xml->addAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    foreach ($urls as $u) {
        $url = $xml->addChild('url');
        $url->addChild('loc', htmlspecialchars($u['loc'], ENT_XML1));
        $url->addChild('lastmod', $u['lastmod']);
        $url->addChild('changefreq', $u['changefreq']);
        $url->addChild('priority', $u['priority']);
    }

    return $xml->asXML() ?: '';
}

/**
 * Save sitemap to storage.
 */
function save_sitemap(): void
{
    $xml = generate_sitemap();
    file_put_contents(STORAGE_PATH . '/sitemap.xml', $xml);
}

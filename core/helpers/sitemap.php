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
    $baseUrl = seo_site_url();

    $pdo  = db();
    $urls = [];
    $now  = time();

    foreach ($types as $type) {
        $prefix = $prefixes[$type] ?? '';

        $stmt = $pdo->prepare("
            SELECT id, slug, updated_at, published_at
            FROM content
            WHERE type = :type
              AND status = 'published'
              AND published_at IS NOT NULL
              AND published_at <= :now
              AND deleted_at IS NULL
        ");
        $stmt->execute(['type' => $type, 'now' => $now]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

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

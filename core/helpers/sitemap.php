<?php
declare(strict_types=1);

/**
 * Generate sitemap XML from database content.
 */
function generate_sitemap(): string
{
    $theme     = theme_config();
    $settings  = load_settings();
    $prefixes  = $settings['content_prefixes'] ?? [];
    $types     = array_keys($theme['content_types'] ?? []);
    $homeSlug  = $settings['homepage_slug'] ?? 'home';

    // Prefer configured base URL
    $baseUrl = rtrim(
        config('site.url')
        ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')),
        '/'
    );

    $pdo  = db();
    $urls = [];

    foreach ($types as $type) {
        $prefix = $prefixes[$type] ?? '';

        $stmt = $pdo->prepare("
            SELECT slug, updated_at, published_at
            FROM content
            WHERE type = :type
              AND status = 'published'
        ");
        $stmt->execute(['type' => $type]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

            // Prefer published date
            $timestamp = $row['published_at'] ?: $row['updated_at'];
            $lastmod   = date('Y-m-d', (int)$timestamp);

            // Homepage handling
            if ($row['slug'] === $homeSlug) {
                $path = '';
            } else {
                $path = trim(($prefix ? "{$prefix}/" : '') . $row['slug'], '/');
            }

            $urls[] = [
                'loc'        => $baseUrl . '/' . $path,
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

    return $xml->asXML();
}

/**
 * Save sitemap to storage.
 */
function save_sitemap(): void
{
    $xml = generate_sitemap();
    file_put_contents(STORAGE_PATH . '/sitemap.xml', $xml);
}
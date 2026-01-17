<?php
declare(strict_types=1);

function generate_sitemap(): string
{
    $theme = theme_config();
    $settings = load_settings();
    $prefixes = $settings['content_prefixes'] ?? [];
    $contentTypes = array_keys($theme['content_types'] ?? []);
    $homepageSlug = $settings['homepage_slug'] ?? 'home';

    // Determine current base URL dynamically
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $protocol . '://' . $host;

    $urls = [];

    foreach ($contentTypes as $type) {
        $prefix = $prefixes[$type] ?? '';
        $folder = STORAGE_PATH . "/content/{$type}/";

        if (!is_dir($folder)) continue;

        foreach (glob($folder . '*.json') as $file) {
            $slug = basename($file, '.json');

            $data = json_decode(file_get_contents($file), true);
            
            // Skip empty files
            if (!$data) continue;

            // Skip drafts
            if (($data['status'] ?? 'draft') !== 'published') {
                continue;
            }

            $updated = isset($data['updated_at']) ? date('Y-m-d', $data['updated_at']) : date('Y-m-d');

            // If this is the homepage, make URL path root
            if ($slug === $homepageSlug) {
                $urlPath = ''; // root
            } else {
                $urlPath = trim(($prefix ? "{$prefix}/" : '') . $slug, '/');
            }

            $urls[] = [
                'loc' => $baseUrl . '/' . $urlPath,
                'lastmod' => $updated,
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];
        }
    }

    // Build XML
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset/>');
    $xml->addAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    foreach ($urls as $u) {
        $url = $xml->addChild('url');
        $url->addChild('loc', htmlspecialchars($u['loc'], ENT_QUOTES | ENT_XML1));
        $url->addChild('lastmod', $u['lastmod']);
        $url->addChild('changefreq', $u['changefreq']);
        $url->addChild('priority', $u['priority']);
    }

    return $xml->asXML();
}


function save_sitemap(): void
{
    $xml = generate_sitemap();
    file_put_contents(CMS_PATH . '/sitemap.xml', $xml);
}

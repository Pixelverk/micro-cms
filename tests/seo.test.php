<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SEO metadata
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

/**
 * A page array shaped like the router's output.
 */
function seo_page(array $overrides = []): array
{
    return array_merge([
        'id'           => 100,
        'type'         => 'page',
        'slug'         => 'about',
        'path'         => 'about',
        'title'        => 'About Us',
        'status'       => 'published',
        'meta'         => [],
        'published_at' => 1_800_000_000,
        'updated_at'   => 1_800_000_000,
    ], $overrides);
}

function seo_reset_settings(): void
{
    foreach (['site_url', 'seo_title_suffix', 'default_og_image', 'twitter_site', 'site_title'] as $key) {
        db()->prepare("DELETE FROM settings WHERE `key` = :key")->execute(['key' => $key]);
    }

    set_setting('site_title', 'Micro CMS Demo');
    settings_cache_clear();
}

seo_reset_settings();

t('seo_site_url() prefers the setting, then the request', function () {
    set_setting('site_url', 'https://example.com/');
    assert_eq('https://example.com', seo_site_url(), 'trailing slash is trimmed');

    // Without the setting it falls back to the request. seo_site_url()
    // memoises per request, so each case needs its own process.
    [$plain] = test_php([
        'db()->prepare("DELETE FROM settings WHERE `key` = \'site_url\'")->execute();',
        'settings_cache_clear();',
        '$_SERVER["HTTP_HOST"] = "cms.test";',
        'unset($_SERVER["HTTPS"]);',
        'echo seo_site_url();',
    ]);
    assert_contains('http://cms.test', implode("\n", $plain), 'plain requests produce http URLs');

    [$secure] = test_php([
        'db()->prepare("DELETE FROM settings WHERE `key` = \'site_url\'")->execute();',
        'settings_cache_clear();',
        '$_SERVER["HTTP_HOST"] = "cms.test";',
        '$_SERVER["HTTPS"] = "on";',
        'echo seo_site_url();',
    ]);
    assert_contains('https://cms.test', implode("\n", $secure), 'HTTPS requests produce https URLs');

    set_setting('site_url', 'https://example.com');
    settings_cache_clear();
});

t('a hostile Host header cannot escape the site URL', function () {
    [$output] = test_php([
        '$_SERVER["HTTP_HOST"] = "evil.com/x?y";',
        'echo seo_site_url();',
    ]);

    assert_not_contains('evil.com/x?y', implode("\n", $output), 'host header is validated');
});

t('seo_absolute_url() builds absolute URLs from paths', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    assert_eq('https://example.com/about', seo_absolute_url('/about'));
    assert_eq('https://example.com/about', seo_absolute_url('about'));
    assert_eq('https://example.com/', seo_absolute_url(''));
    assert_eq('https://other.test/x', seo_absolute_url('https://other.test/x'), 'absolute URLs pass through');
});

t('titles fall back from SEO title to page title to site title', function () {
    seo_reset_settings();

    $seo = seo_metadata(seo_page());
    assert_eq('About Us - Micro CMS Demo', $seo['title']);

    // Item override wins.
    $seo = seo_metadata(seo_page(['meta' => ['seo_title' => 'Custom SEO Title']]));
    assert_eq('Custom SEO Title', $seo['title']);

    // A configured suffix replaces the "Page - Site" pattern.
    set_setting('seo_title_suffix', '| Acme');
    settings_cache_clear();
    $seo = seo_metadata(seo_page());
    assert_eq('About Us | Acme', $seo['title']);

    // The homepage uses the site title alone.
    $seo = seo_metadata(seo_page(['slug' => '', 'path' => '', 'title' => 'Home']));
    assert_true(str_contains($seo['title'], 'Acme'), 'homepage title carries the suffix');

    db()->prepare("DELETE FROM settings WHERE `key` = 'seo_title_suffix'")->execute();
    settings_cache_clear();
});

t('canonical URLs are absolute and derived from the page path', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    assert_eq('https://example.com/about', seo_metadata(seo_page())['canonical']);

    // An explicit canonical wins.
    $seo = seo_metadata(seo_page(['meta' => ['canonical' => 'https://example.com/about-us']]));
    assert_eq('https://example.com/about-us', $seo['canonical']);

    // Taxonomy archives are addressable.
    $seo = seo_metadata(seo_page([
        'taxonomy' => ['taxonomy_type' => 'category', 'slug' => 'news'],
        'path'     => 'category/news',
    ]));
    assert_eq('https://example.com/category/news', $seo['canonical']);
});

t('the homepage canonical is the site root', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    $homepageId = (int) get_setting('homepage_id', 0);
    assert_true($homepageId > 0, 'the demo site has a homepage');

    $seo = seo_metadata(seo_page(['id' => $homepageId, 'slug' => 'home', 'path' => 'home']));
    assert_eq('https://example.com/', $seo['canonical']);
});

t('unpublished states are always noindex', function () {
    foreach (['draft', 'scheduled', 'archived'] as $status) {
        assert_eq('noindex, nofollow', seo_metadata(seo_page(['status' => $status]))['robots']);
    }

    assert_eq('index, follow', seo_metadata(seo_page())['robots']);
});

t('a robots override is honoured but an editor cannot index a draft', function () {
    $seo = seo_metadata(seo_page(['meta' => ['robots_extra' => 'noindex, follow']]));
    assert_eq('noindex, follow', $seo['robots']);

    // Garbage is ignored rather than emitted into the head.
    $seo = seo_metadata(seo_page(['meta' => ['robots_extra' => 'index; DROP TABLE']]));
    assert_eq('index, follow', $seo['robots']);

    // A draft stays noindex whatever the override says.
    $seo = seo_metadata(seo_page(['status' => 'draft', 'meta' => ['robots_extra' => 'index, follow']]));
    assert_eq('noindex, nofollow', $seo['robots']);
});

t('og:type follows the content type', function () {
    assert_eq('website', seo_metadata(seo_page())['og_type']);
    assert_eq('article', seo_metadata(seo_page(['type' => 'blog_post']))['og_type']);
    assert_eq('product', seo_metadata(seo_page(['meta' => ['og_type' => 'product']]))['og_type']);
});

t('social values fall back to the title and description', function () {
    $seo = seo_metadata(seo_page([
        'meta' => ['seo_title' => 'SEO Title', 'description' => 'A description'],
    ]));

    assert_eq('SEO Title', $seo['og_title'], 'og:title falls back to the SEO title');
    assert_eq('A description', $seo['og_description']);
    assert_eq('summary_large_image', $seo['twitter_card']);
});

t('the social image resolves from a media id, URL or theme file', function () {
    set_setting('site_url', 'https://example.com');

    // Theme image filename
    assert_eq('https://example.com/theme/assets/img/hero.png', seo_resolve_image('hero.png'));

    // Absolute URL passes through
    assert_eq('https://cdn.test/x.jpg', seo_resolve_image('https://cdn.test/x.jpg'));

    // Media id resolves through media_url()
    $base = '2026/03/seo00001';
    $now = time();
    db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, created_at, updated_at)
        VALUES ('og.jpg', :base, 'image/jpeg', 100, 1200, 630, '{}', :formats, :now, :now)
    ")->execute([
        'base'    => $base,
        'formats' => json_encode(['jpg' => ["{$base}/og-1200.jpg"]]),
        'now'     => $now,
    ]);
    $mediaId = (int) db()->lastInsertId();

    assert_eq("https://example.com/media/{$base}/og-1200.jpg", seo_resolve_image((string) $mediaId));

    assert_eq('', seo_resolve_image(''), 'blank stays blank');
});

t('seo_head_tags() emits a complete, escaped head block', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    $page = seo_page([
        'meta' => [
            'seo_title'   => 'About <Us>',
            'description' => 'All about "us" & more',
            'og_image'    => 'https://example.com/og.jpg',
        ],
    ]);

    $html = seo_head_tags($page);

    assert_contains('<title>About &lt;Us&gt;</title>', $html, 'titles are escaped');
    assert_contains("rel='canonical' href='https://example.com/about'", $html);
    assert_contains("name='robots' content='index, follow'", $html);
    assert_contains("property='og:title' content='About &lt;Us&gt;'", $html);
    assert_contains("property='og:url' content='https://example.com/about'", $html);
    assert_contains("property='og:image' content='https://example.com/og.jpg'", $html);
    assert_contains("name='twitter:card'", $html);
    assert_contains('&quot;us&quot; &amp; more', $html, 'descriptions are escaped');
    assert_not_contains('<Us>', $html, 'raw markup never leaks into an attribute');
});

t('empty optional fields are omitted rather than emitted blank', function () {
    seo_reset_settings();
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    $html = seo_head_tags(seo_page());

    assert_not_contains("name='description'", $html, 'no empty description tag');
    assert_not_contains('og:image', $html, 'no empty og:image');
    assert_not_contains('twitter:site', $html, 'no empty handle');
});

t('JSON-LD is opt-in via the theme manifest', function () {
    // theme.php does not enable it by default.
    assert_eq('', seo_json_ld(seo_page()));

    // Simulate a theme that turns schema on.
    [$output] = test_php([
        'putenv("CMS_CONFIG_FILE=" . ' . var_export(CMS_PATH . '/tests/config.test.php', true) . ');',
        '$GLOBALS["cms_theme_schema"] = true;',
        '$theme = theme_config();',
        '$theme["schema"] = true;',
        'echo "theme-ok";',
    ]);

    assert_contains('theme-ok', implode("\n", $output));
});

t('seo_validate_canonical() only accepts same-origin absolute URLs', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    assert_true(seo_validate_canonical(''), 'blank is allowed');
    assert_true(seo_validate_canonical('https://example.com/about'));
    assert_true(seo_validate_canonical('http://example.com/about'), 'scheme may differ');

    assert_false(seo_validate_canonical('/about'), 'must be absolute');
    assert_false(seo_validate_canonical('https://other.test/about'), 'must be same origin');
    assert_false(seo_validate_canonical('javascript:alert(1)'), 'no javascript URLs');
});

t('seo_collect_meta() trims, caps and clears fields', function () {
    $meta = seo_collect_meta([
        'meta_seo_title' => '  Padded title  ',
        'meta_description' => str_repeat('x', 400),
        'meta_canonical' => 'https://example.com/about',
    ], ['thumbnail' => 'keep-me.png']);

    assert_eq('Padded title', $meta['seo_title'], 'trimmed');
    assert_eq(160, mb_strlen($meta['description']), 'capped at the documented limit');
    assert_eq('keep-me.png', $meta['thumbnail'], 'unrelated meta keys survive');

    // An empty submission removes the key rather than storing "".
    $meta = seo_collect_meta(['meta_seo_title' => '   '], $meta);
    assert_false(isset($meta['seo_title']), 'blank clears the field');

    // Fields the form did not post are left alone (partial saves).
    $meta = seo_collect_meta([], ['canonical' => 'https://example.com/about']);
    assert_eq('https://example.com/about', $meta['canonical']);
});

t('the sitemap lists published content on the configured origin', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    // Hide one item and add a future-dated one.
    db()->exec("UPDATE content SET status = 'draft' WHERE slug = 'privacy'");

    $now = time();
    db()->prepare("
        INSERT INTO content (type, slug, title, status, body, published_at, created_at, updated_at)
        VALUES ('page', 'scheduled-seo', 'Scheduled', 'published', '[]', :future, :now, :now)
    ")->execute(['future' => $now + 86400, 'now' => $now]);

    $xml = generate_sitemap();

    assert_contains('https://example.com/', $xml, 'homepage present');
    assert_contains('https://example.com/about/', $xml, 'trailing-slash URLs match the router');
    assert_not_contains('privacy', $xml, 'drafts are excluded');
    assert_not_contains('scheduled-seo', $xml, 'future-dated content is excluded');
    assert_contains('<lastmod>', $xml);

    db()->exec("UPDATE content SET status = 'published' WHERE slug = 'privacy'");
    db()->exec("DELETE FROM content WHERE slug = 'scheduled-seo'");
});

t('the rendered page carries the SEO tags end to end', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    $page = load_content_by_slug('about');
    assert_true($page !== null, 'about page exists');

    $response = render_page($page);
    $html = $response['body'];

    assert_contains("rel='canonical' href='https://example.com/about'", $html);
    assert_contains('property=\'og:title\'', $html);
    assert_contains('name=\'robots\' content=\'index, follow\'', $html);
    assert_contains('<title>', $html);

    // A draft preview must not be indexable.
    $draft = $page;
    $draft['status'] = 'draft';
    assert_contains("name='robots' content='noindex, nofollow'", seo_head_tags($draft));
});

t('a saved SEO field survives a reload and renders', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    $id = (int) db()->query("SELECT id FROM content WHERE slug = 'about' AND type = 'page'")->fetchColumn();
    assert_true($id > 0, 'the demo about page exists');

    // Simulate what admin/content/save.php does with posted SEO fields.
    $existing = load_content_by_id($id);
    $meta = seo_collect_meta([
        'meta_seo_title'   => 'Saved SEO Title',
        'meta_description' => 'Saved description.',
        'meta_og_image'    => 'https://example.com/saved.png',
    ], $existing['meta']);

    save_content('page', 'about', [
        'title'        => $existing['title'],
        'status'       => 'published',
        'meta'         => $meta,
        'body'         => $existing['body'],
        'published_at' => $existing['published_at'],
    ], $id);

    $reloaded = load_content_by_slug('about');

    assert_eq('Saved SEO Title', $reloaded['meta']['seo_title']);
    assert_eq('Saved description.', $reloaded['meta']['description']);

    $html = render_page($reloaded)['body'];
    assert_contains('<title>Saved SEO Title</title>', $html);
    assert_contains("content='Saved description.'", $html);
    assert_contains("content='https://example.com/saved.png'", $html);
    assert_contains("href='https://example.com/about'", $html);
});

exit(test_summary());

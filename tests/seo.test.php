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

t('a 404 response is noindex but followable', function () {
    $seo = seo_metadata(seo_page(['status' => '404']));

    assert_contains('noindex', $seo['robots']);
    assert_contains('follow', $seo['robots']);

    // An editor override cannot make a 404 indexable.
    $seo = seo_metadata(seo_page(['status' => '404', 'meta' => ['robots_extra' => 'index, follow']]));
    assert_contains('noindex', $seo['robots']);
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

t('the social image resolves from a media id or a URL', function () {
    set_setting('site_url', 'https://example.com');

    // A bare filename is not an image, so there is nothing to share
    assert_eq('', seo_resolve_image('hero.png'));

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

t('JSON-LD is emitted for a page when the theme enables it', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    $html = seo_json_ld(seo_page());

    assert_contains("type='application/ld+json'", $html);
    assert_contains('"@type":"WebPage"', $html);
    assert_contains('"url":"https://example.com/about"', $html);

    // A blog post is an Article carrying its author and dates.
    $article = seo_json_ld(seo_page([
        'type' => 'blog_post',
        'meta' => ['author' => 'Ada Lovelace'],
    ]));

    assert_contains('"@type":"Article"', $article);
    assert_contains('"@type":"Person"', $article);
    assert_contains('"name":"Ada Lovelace"', $article);
});

t('the homepage JSON-LD also identifies the organisation', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    $homepageId = (int) get_setting('homepage_id', 0);
    assert_true($homepageId > 0, 'the demo site has a homepage');

    $html = seo_json_ld(seo_page(['id' => $homepageId, 'slug' => '', 'path' => '']));

    assert_contains('"@type":"Organization"', $html);
    assert_contains('"url":"https://example.com/"', $html);

    // Other pages do not repeat it.
    assert_not_contains('Organization', seo_json_ld(seo_page(['id' => $homepageId + 1])));
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

t('the SEO fields are grouped into the cards the editor shows', function () {
    $groups = seo_editable_field_groups();

    assert_eq(['search', 'social'], array_column($groups, 'key'), 'search comes first, then social');
    assert_eq('Search', $groups[0]['label']);
    assert_eq('Social', $groups[1]['label']);

    assert_eq(['seo_title', 'description', 'canonical', 'robots_extra'], array_keys($groups[0]['fields']));
    assert_eq(['og_title', 'og_description', 'og_image', 'author', 'twitter_site', 'twitter_creator'], array_keys($groups[1]['fields']));

    // Every editable field reaches exactly one card, so a new field cannot be
    // left out of the form.
    $grouped = array_merge(...array_column($groups, 'fields'));

    assert_eq(array_keys(seo_editable_fields()), array_keys($grouped), 'the cards hold every editable field, in order');
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

t('the sitemap skips items whose robots override is noindex', function () {
    set_setting('site_url', 'https://example.com');
    settings_cache_clear();

    $now = time();
    db()->prepare("
        INSERT INTO content (type, slug, title, status, meta, body, published_at, created_at, updated_at)
        VALUES ('page', 'noindex-sitemap', 'Noindex Sitemap', 'published', :meta, '[]', :now, :now, :now)
    ")->execute(['meta' => json_encode(['robots_extra' => 'noindex, follow']), 'now' => $now]);

    $xml = generate_sitemap();

    assert_not_contains('noindex-sitemap', $xml, 'a noindex page is not advertised');
    assert_contains('https://example.com/about/', $xml, 'other pages are still listed');

    db()->exec("DELETE FROM content WHERE slug = 'noindex-sitemap'");
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

t('the social image field is wired to the media picker', function () {
    assert_eq('media', seo_editable_fields()['og_image']['type']);

    // The SEO loop renders that type as a picker field, not a plain text input.
    $editor = (string) file_get_contents(CMS_PATH . '/admin/content/edit.php');

    assert_contains("=== 'media'", $editor, 'edit.php branches on the media field type');
    assert_contains("admin_trans('media_no_image')", $editor, 'the field renders a preview');
    assert_contains('data-image-picker', $editor, 'the field gets the picker behaviour hook');
});

// ---------------------------------------------------------------------------
// App icons, the manifest and article metadata
// ---------------------------------------------------------------------------

t('the theme provides the icons an installed site needs', function () {
    $icons = seo_icon_candidates();

    assert_true($icons !== [], 'the shipped theme offers icons');

    // Nothing generated: each one is a real file the theme ships.
    foreach ($icons as $icon) {
        assert_true($icon['width'] > 0 && $icon['height'] > 0, 'every icon knows its size');
        assert_contains('image/', $icon['type'], 'and its type');
        assert_contains('/theme/assets/', $icon['src'], 'and points at a theme file');
    }

    $app = seo_app_icons();

    assert_eq('192x192', $app[0]['sizes'], 'the first manifest icon is the 192 one');
    assert_eq('512x512', $app[1]['sizes'], 'and the second is the 512 one');

    $apple = seo_icon_at($icons, 180);
    assert_eq(192, $apple['width'], 'the apple icon is the smallest one big enough');
});

t('a manifest is built from the site settings and stays valid without icons', function () {
    set_setting('site_title', 'Manifest Site');
    set_setting('site_description', 'A site about manifests.');
    set_setting('theme_color', '#123456');
    settings_cache_clear();

    $manifest = seo_manifest();

    assert_eq('Manifest Site', $manifest['name']);
    assert_eq('A site about manifests.', $manifest['description']);
    assert_eq('#123456', $manifest['theme_color'], 'Settings wins over the theme colour');
    assert_eq('standalone', $manifest['display']);
    assert_eq('/', $manifest['start_url']);
    assert_true(!empty($manifest['icons']), 'the icons are listed');

    $decoded = json_decode(seo_manifest_json(), true);
    assert_true(is_array($decoded), 'the manifest is valid JSON');
    assert_eq($manifest['name'], $decoded['name'], 'and round-trips');

    // An empty setting falls back to the colour the theme declares.
    set_setting('theme_color', '');
    settings_cache_clear();

    $bare = seo_manifest();
    assert_eq('#212529', (string) ($bare['theme_color'] ?? ''), 'the theme colour is the fallback');
    assert_true(isset($bare['icons']), 'and the theme icons are still there');
});

t('the head offers the manifest, the apple icon and the browser colour', function () {
    set_setting('site_url', 'https://example.com');
    set_setting('theme_color', '#0f172a');
    settings_cache_clear();

    $head = seo_app_head_tags();

    // url() is root-relative unless the deployment configures a base URL, which
    // is a valid href for a manifest link either way.
    assert_contains("rel='manifest'", $head);
    assert_contains("href='/site.webmanifest'", $head);
    assert_contains("rel='apple-touch-icon'", $head);
    assert_contains("<meta name='theme-color' content='#0f172a'>", $head);

    // A theme may declare a dark-mode colour; an empty one must not be emitted.
    assert_not_contains('prefers-color-scheme', $head, 'no dark tag until a theme asks for one');
});

t('articles carry their times, author, section and tags', function () {
    $post = load_content_by_slug('blog/welcome-to-our-blog');
    assert_true($post !== null, 'the demo post exists');

    $post['meta'] = array_merge($post['meta'] ?? [], ['author' => 'Ada Lovelace']);
    $head = seo_head_tags($post);

    assert_contains("property='article:published_time'", $head);
    assert_contains("property='article:modified_time'", $head);
    assert_contains("property='article:author' content='Ada Lovelace'", $head);
    assert_contains("property='article:section'", $head);
    assert_contains("property='article:tag'", $head);

    // The times are ISO 8601 in UTC.
    preg_match("/article:published_time' content='([^']+)'/", $head, $matches);
    assert_eq(gmdate('c', (int) $post['published_at']), $matches[1] ?? '', 'published time is ISO 8601 UTC');

    // A page is not an article, so none of it is emitted.
    $page = load_content_by_slug('about');
    $pageHead = seo_head_tags($page);

    assert_not_contains('article:published_time', $pageHead, 'a page has no publication time');
    assert_not_contains('article:tag', $pageHead, 'and no tags');
});

t('the creator falls back to the site handle, and is overridable per item', function () {
    set_setting('twitter_site', '@site');
    settings_cache_clear();

    $page = load_content_by_slug('about');
    $page['meta'] = array_diff_key($page['meta'] ?? [], ['twitter_creator' => '']);

    assert_contains("name='twitter:creator' content='@site'", seo_head_tags($page), 'the site handle is the fallback');

    $page['meta']['twitter_creator'] = '@writer';

    $head = seo_head_tags($page);
    assert_contains("name='twitter:creator' content='@writer'", $head, 'the item wins');
    assert_contains("name='twitter:site' content='@site'", $head, 'and the site handle stays');
});

t('the author field is editable and reaches the head', function () {
    assert_true(isset(seo_editable_fields()['author']), 'the editor offers an author');
    assert_true(isset(seo_editable_fields()['twitter_creator']), 'and a creator handle');

    $meta = seo_collect_meta(['meta_author' => 'Grace Hopper'], []);

    assert_eq('Grace Hopper', $meta['author'] ?? '', 'a posted author is kept');
});

exit(test_summary());

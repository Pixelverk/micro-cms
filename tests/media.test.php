<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Media rendering helpers
|--------------------------------------------------------------------------
| picture() and media_url() are the theme-facing image API. They must cope
| with every shape the uploader produces, including smaller-than-requested
| images and non-image files.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

/**
 * Insert a media row and return its id.
 */
function seed_media(array $overrides = []): int
{
    $now = time();

    $row = array_merge([
        'original_name' => 'photo.jpg',
        'base_path'     => '2026/03/' . bin2hex(random_bytes(4)),
        'mime_type'     => 'image/jpeg',
        'original_size' => 12345,
        'width'         => 1200,
        'height'        => 800,
        'sizes_json'    => '{}',
        'formats_json'  => '{}',
        'lqip_base64'   => null,
        'alt_text'      => 'A nice photo',
        'description'   => null,
        'created_at'    => $now,
        'updated_at'    => $now,
    ], $overrides);

    $stmt = db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, alt_text, description,
                           created_at, updated_at)
        VALUES (:original_name, :base_path, :mime_type, :original_size, :width, :height,
                :sizes_json, :formats_json, :lqip_base64, :alt_text, :description,
                :created_at, :updated_at)
    ");
    $stmt->execute($row);

    return (int) db()->lastInsertId();
}

t('media_by_id() is null for unknown ids', function () {
    assert_eq(null, media_by_id(0));
    assert_eq(null, media_by_id(999999));
});

t('media_url() picks the variant nearest the requested width', function () {
    $base = '2026/03/abc123';
    $id = seed_media([
        'base_path'    => $base,
        'formats_json' => json_encode([
            'webp' => ["{$base}/photo-320.webp", "{$base}/photo-640.webp", "{$base}/photo-1280.webp"],
            'jpg'  => ["{$base}/photo-320.jpg", "{$base}/photo-640.jpg"],
        ]),
    ]);

    assert_contains('photo-1280.webp', media_url($id, 1280), 'closest webp variant');
    assert_contains('photo-320.webp', media_url($id, 100), 'smallest when below the range');
    assert_contains('photo-640.webp', media_url($id, 700), 'nearest when between variants');

    // Explicit format wins.
    assert_contains('photo-640.jpg', media_url($id, 640, 'jpg'));
});

t('media_url() supports non-image media', function () {
    $base = '2026/03/deadbeef';
    $id = seed_media([
        'original_name' => 'report.pdf',
        'base_path'     => $base,
        'mime_type'     => 'application/pdf',
        'width'         => 0,
        'height'        => 0,
        'formats_json'  => json_encode(['pdf' => ["{$base}/report.pdf"]]),
    ]);

    assert_contains('report.pdf', media_url($id));
    assert_contains('media/' . $base, media_url($id));
});

t('media_url() rebuilds the original path when no variants exist', function () {
    $base = '2026/03/nnnnnnnn';
    $id = seed_media([
        'original_name' => 'My Photo.JPG',
        'base_path'     => $base,
        'formats_json'  => '{}',
    ]);

    // sanitize_slug() lowercases and dashes the stem.
    assert_eq(url("media/{$base}/my-photo.jpg"), media_url($id));
});

t('media_url() returns empty for a missing row', function () {
    assert_eq('', media_url(999999));
});

t('picture() renders srcset, sizes, LQIP and alt text', function () {
    $base = '2026/03/pic00001';
    $id = seed_media([
        'base_path'    => $base,
        'alt_text'     => 'Sunset over water',
        'lqip_base64'  => 'data:image/gif;base64,R0lGOD',
        'formats_json' => json_encode([
            'webp' => ["{$base}/photo-640.webp"],
            'jpg'  => ["{$base}/photo-640.jpg"],
        ]),
    ]);

    $html = picture($id);

    assert_contains('<picture>', $html);
    assert_contains('type="image/webp"', $html);
    assert_contains('photo-640.webp 640w', $html);
    assert_contains('photo-640.jpg 640w', $html, 'the fallback format is the img srcset');
    assert_contains('alt="Sunset over water"', $html);
    assert_contains('loading="lazy"', $html);
    assert_contains('background-image:url(data:image/gif;base64,R0lGOD)', $html, 'LQIP becomes a background');
    assert_contains('width="1200"', $html);
    assert_contains('height="800"', $html);
});

t('picture() only uses the largest available variant for small images', function () {
    // The uploader skips targets above the source width, so a 500px upload may
    // only ever have a 320px variant. It must still render.
    $base = '2026/03/small001';
    $id = seed_media([
        'base_path'    => $base,
        'width'        => 500,
        'height'       => 300,
        'formats_json' => json_encode([
            'webp' => ["{$base}/photo-320.webp"],
            'jpg'  => ["{$base}/photo-320.jpg"],
        ]),
    ]);

    $html = picture($id);

    assert_contains('photo-320.jpg 320w', $html);
    assert_contains('photo-320.webp 320w', $html);
    assert_not_contains('1280', $html);
});

t('picture() keeps attribute overrides from the caller', function () {
    $base = '2026/03/ovr00001';
    $id = seed_media([
        'base_path'    => $base,
        'formats_json' => json_encode(['jpg' => ["{$base}/photo-640.jpg"]]),
    ]);

    $html = picture($id, ['alt' => 'Custom alt', 'loading' => 'eager', 'class' => 'hero-image']);

    assert_contains('alt="Custom alt"', $html);
    assert_contains('loading="eager"', $html);
    assert_contains('class="hero-image"', $html);
});

t('picture() returns empty for non-images and missing variants', function () {
    $pdf = seed_media([
        'original_name' => 'doc.pdf',
        'mime_type'     => 'application/pdf',
        'formats_json'  => json_encode(['pdf' => ['2026/03/x/doc.pdf']]),
    ]);

    assert_eq('', picture($pdf), 'non-images must use media_url()');

    $noVariants = seed_media(['formats_json' => '{}']);
    assert_eq('', picture($noVariants), 'no variants means no picture element');

    assert_eq('', picture(999999), 'missing row');
});

t('resolve_image_value() picks a media variant width', function () {
    $base = '2026/03/riv00001';
    $id = seed_media([
        'base_path'    => $base,
        'formats_json' => json_encode([
            'webp' => ["{$base}/photo-320.webp", "{$base}/photo-1280.webp"],
        ]),
    ]);

    assert_contains('photo-1280.webp', resolve_image_value((string) $id, 1200), 'closest variant to the requested width');
    assert_eq(img('icon-512.png'), resolve_image_value('icon-512.png'), 'theme filenames still resolve through img()');
    assert_eq('', resolve_image_value('gone.png'), 'a filename the theme does not ship resolves to nothing, not a 404');
    assert_eq('https://example.test/x.jpg', resolve_image_value('https://example.test/x.jpg'));
    assert_eq('', resolve_image_value(''));
});

t('render_image() uses picture() for media ids and a bare img otherwise', function () {
    $base = '2026/03/rim00001';
    $id = seed_media([
        'base_path'    => $base,
        'alt_text'     => 'From the library',
        'formats_json' => json_encode([
            'webp' => ["{$base}/photo-640.webp"],
            'jpg'  => ["{$base}/photo-640.jpg"],
        ]),
    ]);

    $media = render_image((string) $id, ['class' => 'card-img-top']);

    assert_contains('<picture>', $media);
    assert_contains('image-wrapper', $media);
    assert_contains('photo-640.webp 640w', $media);
    assert_contains('class="card-img-top"', $media);
    assert_contains('alt="From the library"', $media, 'the media row supplies the alt');

    // A theme asset must stay a bare <img>. main.js only reveals images inside
    // .image-wrapper picture, so wrapping one would leave it invisible.
    $asset = render_image('icon-512.png', ['class' => 'img-fluid', 'alt' => 'The app icon']);

    assert_contains('<img', $asset);
    assert_contains('class="img-fluid"', $asset);
    assert_contains('alt="The app icon"', $asset);
    assert_not_contains('image-wrapper', $asset);
    assert_not_contains('<picture>', $asset);

    // A filename the theme does not ship — and the `:placeholder` a schema
    // default uses — is the CMS's placeholder box, never a URL that 404s.
    $missing = render_image('gone.png', ['class' => 'img-fluid', 'alt' => 'Gone']);

    assert_contains('<span class="img-fluid image-placeholder"', $missing);
    assert_contains('aria-hidden="true"', $missing);
    assert_contains('aspect-ratio: 3 / 2', $missing, 'the default slot shape');
    assert_not_contains('<img', $missing);
    assert_not_contains('alt=', $missing, 'there is no image to describe');

    $square = render_image(':placeholder', ['class' => 'rounded-circle', 'ratio' => '1']);

    assert_contains('aspect-ratio: 1', $square, 'the caller says what shape the slot is');
    assert_not_contains('ratio=', $square, 'the ratio is never emitted as an attribute');

    // A real image never picks the ratio up either.
    assert_not_contains('ratio', render_image('icon-512.png', ['ratio' => '1']));

    assert_eq('', render_image(''));
    assert_eq('', render_image(null));
});

t('picture() renders an upload that was already webp', function () {
    // The uploader generates the webp ladder plus the source format; for a webp
    // source those are the same, so formats_json holds webp alone. It must still
    // render rather than returning nothing.
    $base = '2026/03/webponly';
    $id = seed_media([
        'original_name' => 'photo.webp',
        'base_path'     => $base,
        'mime_type'     => 'image/webp',
        'formats_json'  => json_encode([
            'webp' => ["{$base}/photo-320.webp", "{$base}/photo-640.webp"],
        ]),
    ]);

    $html = picture($id);

    assert_contains('<picture>', $html);
    assert_contains('src="/media/' . $base . '/photo-320.webp"', $html, 'the img falls back to a webp variant');
    assert_contains('photo-640.webp 640w', $html);
    assert_not_contains('type="image/webp"', $html, 'a webp source would repeat the same srcset');

    assert_contains('<picture>', render_image((string) $id), 'and it renders through the theme helper');
});

t('render_image() falls back to the original file when there are no variants', function () {
    // An image the generator could not encode keeps its original, and an editor
    // who chose it should still get an <img>.
    $base = '2026/03/origonly';
    $id = seed_media([
        'original_name' => 'My Photo.JPG',
        'base_path'     => $base,
        'formats_json'  => '{}',
    ]);

    assert_eq('', picture($id), 'picture() has nothing to build a srcset from');

    $html = render_image((string) $id, ['class' => 'img-fluid']);

    assert_contains('<img', $html);
    assert_contains('src="' . url("media/{$base}/my-photo.jpg") . '"', $html);
    assert_contains('class="img-fluid"', $html);

    // A non-image id is not an image, even with a fallback available.
    $pdf = seed_media([
        'original_name' => 'doc.pdf',
        'mime_type'     => 'application/pdf',
        'formats_json'  => json_encode(['pdf' => ['2026/03/x/doc.pdf']]),
    ]);

    assert_eq('', render_image((string) $pdf));
});

t('media_usage_map() finds where a file is referenced', function () {
    $base  = '2026/03/used0001';
    $spare = '2026/03/spare001';

    $id    = seed_media(['base_path' => $base, 'formats_json' => json_encode(['webp' => ["{$base}/photo-640.webp"]])]);
    $other = seed_media(['base_path' => $spare, 'formats_json' => '{}']);

    // A page that names $id in an image prop and in the logo setting, and
    // $other only by path — once pasted into rich text, once in a menu.
    $body = [
        ['type' => 'hero-section', 'props' => ['title' => 'Hi', 'subtitle' => 'There', 'image' => (string) $id], 'children' => []],
        // `limit` is a number too, but it is not an image field, so it must not
        // be read as a media id.
        ['type' => 'blog-stories-section', 'props' => ['title' => 'News', 'limit' => (string) $other], 'children' => []],
        ['type' => 'quill-editor', 'props' => ['content' => '<img src="/media/' . $spare . '/photo-640.jpg">'], 'children' => []],
    ];

    db()->prepare("
        INSERT INTO content (type, title, slug, status, meta, body, created_at, updated_at)
        VALUES ('page', 'Usage Page', 'usage-page', 'published', ?, ?, :now, :now)
    ")->execute([json_encode(['thumbnail' => (string) $id]), json_encode($body), 'now' => time()]);

    set_setting('logo', (string) $id);
    // A numeric setting is not a media reference either.
    set_setting('quality_webp', (string) $id);
    settings_cache_clear();

    db()->prepare("INSERT INTO menus (label, slug, items, updated_at) VALUES ('Usage Menu', 'usage-menu', ?, :now)")
        ->execute([json_encode([['label' => 'File', 'url' => '/media/' . $spare . '/photo-640.jpg']]), 'now' => time()]);

    $map = media_usage_map();

    assert_eq(['Page “Usage Page”', 'Site settings (logo)'], $map[$id] ?? [], 'an image prop and a setting');
    assert_eq(['Page “Usage Page”', 'Menu “Usage Menu”'], $map[$other] ?? [], 'a path in rich text and in a menu');

    // A file nothing points at is absent rather than listed with no places.
    $unused = seed_media(['base_path' => '2026/03/unused01']);
    assert_false(isset($map[$unused]));
});

t('media_delete() removes the row and the folder, and refuses bad input', function () {
    $now = time();

    $insert = db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, alt_text, description,
                           created_at, updated_at)
        VALUES (:name, :base, 'image/jpeg', 100, 10, 10, '{}', '{}', NULL, '', NULL, :now, :now)
    ");

    // A real row with files on disk, in the YYYY/MM folders the uploader makes.
    $base   = '2026/03/del00001';
    $folder = STORAGE_PATH . '/media/' . $base;
    @mkdir($folder, 0777, true);
    file_put_contents($folder . '/photo-640.jpg', 'x');

    $insert->execute(['name' => 'delete-me.jpg', 'base' => $base, 'now' => $now]);
    $id = (int) db()->lastInsertId();

    assert_eq('deleted', media_delete($id));
    assert_false(is_dir($folder), 'the folder is gone');

    $check = db()->prepare("SELECT COUNT(*) FROM media WHERE id = ?");
    $check->execute([$id]);
    assert_eq(0, (int) $check->fetchColumn(), 'the row is gone');

    // Empty YYYY/MM parents are tidied up behind it.
    assert_false(is_dir(STORAGE_PATH . '/media/2026/03'), 'the month folder is removed too');

    assert_eq('not_found', media_delete(999999), 'an unknown id is reported, not deleted');
    assert_eq('not_found', media_delete($id), 'deleting twice is a no-op');

    // A base path that climbs out of the media directory must never be followed.
    $insert->execute(['name' => 'escape.jpg', 'base' => '../../storage', 'now' => $now]);
    $escapeId = (int) db()->lastInsertId();

    assert_eq('invalid_path', media_delete($escapeId), 'a traversal path is refused');
    $check->execute([$escapeId]);
    assert_eq(1, (int) $check->fetchColumn(), 'and its row is left alone');
});

t('media_orphans() reports both directions and the repair is careful', function () {
    $now = time();

    $insert = db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, alt_text, description,
                           created_at, updated_at)
        VALUES (:name, :base, 'image/jpeg', 100, 10, 10, '{}', '{}', NULL, '', NULL, :now, :now)
    ");

    $mediaRoot = STORAGE_PATH . '/media';

    // A row and its folder: in step, so neither direction reports it.
    $paired = '2026/04/aaaa0001';
    @mkdir($mediaRoot . '/' . $paired, 0777, true);
    file_put_contents($mediaRoot . '/' . $paired . '/a.jpg', str_repeat('a', 100));
    $insert->execute(['name' => 'paired.jpg', 'base' => $paired, 'now' => $now]);

    // A folder with no row.
    $orphan = '2026/04/bbbb0002';
    @mkdir($mediaRoot . '/' . $orphan, 0777, true);
    file_put_contents($mediaRoot . '/' . $orphan . '/b.jpg', str_repeat('b', 2048));

    // A row with no folder — the class media_delete() cannot remove.
    $ghost = '2026/04/cccc0003';
    $insert->execute(['name' => 'ghost.jpg', 'base' => $ghost, 'now' => $now]);
    $ghostId = (int) db()->lastInsertId();

    $scan     = media_orphans();
    $fileList = array_column($scan['files'], 'path');
    $rowList  = array_column($scan['rows'], 'base_path');

    assert_true(in_array($orphan, $fileList, true), 'a folder with no row is reported');
    assert_false(in_array($paired, $fileList, true), 'a paired folder is not');
    assert_true(in_array($ghost, $rowList, true), 'a row with no folder is reported');
    assert_false(in_array($paired, $rowList, true), 'a paired row is not');

    // The reported size is the folder's, not a guess.
    $sizes = array_column($scan['files'], 'bytes', 'path');
    assert_eq(2048, (int) ($sizes[$orphan] ?? 0), 'the folder size is measured');

    // A path still referenced by content is never deleted.
    seed_content([
        'slug'         => 'orphan-reference',
        'title'        => 'Orphan reference',
        'status'       => 'published',
        'published_at' => $now,
        'meta'         => ['description' => 'd'],
        'body'         => [['type' => 'quill-editor', 'props' => ['content' => '<img src="/media/' . $orphan . '/b.jpg">'], 'children' => []]],
    ]);

    assert_true(in_array($orphan, media_referenced_paths(), true), 'the path reference is seen');
    assert_eq(0, media_delete_orphans([$orphan]), 'a referenced folder is kept');
    assert_true(is_dir($mediaRoot . '/' . $orphan), 'and stays on disk');

    // Once nothing references it, the repair removes it.
    db()->exec("DELETE FROM content WHERE slug = 'orphan-reference'");
    assert_eq(1, media_delete_orphans([$orphan]), 'an unreferenced folder is removed');
    assert_false(is_dir($mediaRoot . '/' . $orphan), 'and is gone');

    // A folder a row owns is never touched, even if asked for.
    assert_eq(0, media_delete_orphans([$paired]), 'a folder with a row is refused');
    assert_true(is_dir($mediaRoot . '/' . $paired), 'and stays');

    // A row with no folder cannot be removed through the library...
    assert_eq('invalid_path', media_delete($ghostId), 'media_delete() refuses it');
    assert_eq(1, (int) db()->query("SELECT COUNT(*) FROM media WHERE id = {$ghostId}")->fetchColumn(), 'and leaves the row');

    // ...but the scan repair can.
    assert_eq(1, media_delete_missing_rows([$ghostId]), 'the repair removes the row');
    assert_eq(0, (int) db()->query("SELECT COUNT(*) FROM media WHERE id = {$ghostId}")->fetchColumn(), 'and it is gone');
});

exit(test_summary());

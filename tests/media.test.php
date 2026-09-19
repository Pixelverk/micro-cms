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
        'title'         => null,
        'alt_text'      => 'A nice photo',
        'description'   => null,
        'created_at'    => $now,
        'updated_at'    => $now,
    ], $overrides);

    $stmt = db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, title, alt_text, description,
                           created_at, updated_at)
        VALUES (:original_name, :base_path, :mime_type, :original_size, :width, :height,
                :sizes_json, :formats_json, :lqip_base64, :title, :alt_text, :description,
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
    assert_eq(img('logo.png'), resolve_image_value('logo.png'), 'theme filenames still resolve through img()');
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
    $asset = render_image('600x400.png', ['class' => 'img-fluid', 'alt' => 'Placeholder']);

    assert_contains('<img', $asset);
    assert_contains('class="img-fluid"', $asset);
    assert_contains('alt="Placeholder"', $asset);
    assert_not_contains('image-wrapper', $asset);
    assert_not_contains('<picture>', $asset);

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

exit(test_summary());

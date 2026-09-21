<?php
declare(strict_types=1);



/**
 * Render an image value in a template.
 *
 * An image value is a media id or an absolute URL; the editor's images are the
 * media library's. A media id gets the responsive picture() block (WebP srcset,
 * LQIP, alt from the media row). A URL gets a plain <img>, exactly the markup
 * the theme used before, so it is never wrapped in picture()'s LQIP wrapper —
 * main.js only un-blurs `.image-wrapper picture img`, and a bare <img> there
 * would stay invisible.
 *
 * Anything else — the `:placeholder` a component schema uses as its default, or
 * a value left over from a theme that used to ship the file — renders the CMS
 * placeholder box. `$attrs['ratio']` is the shape that box takes; it defaults to
 * 3:2 and is never emitted on an <img>.
 */
function render_image(mixed $value, array $attrs = []): string
{
    $ratio = trim((string) ($attrs['ratio'] ?? '3 / 2'));
    unset($attrs['ratio']);

    $value = is_scalar($value) ? trim((string) $value) : '';

    if ($value === '') {
        return '';
    }

    if (ctype_digit($value)) {
        $picture = picture((int) $value, $attrs);

        if ($picture !== '') {
            return $picture;
        }

        // picture() needs recorded variants. A row without them (an upload the
        // generator could not encode, or an older import) still has its original
        // file, and an editor who chose that image should not get nothing.
        $media = media_by_id((int) $value);

        if ($media === null || !media_is_image($media)) {
            return '';
        }

        $url = media_url((int) $value);
    } elseif (preg_match('#^https?://#i', $value)) {
        $url = $value;
    } else {
        return image_placeholder($ratio, $attrs);
    }

    if ($url === '') {
        return '';
    }

    $attrs['src'] = $url;

    $attrString = '';
    foreach ($attrs as $name => $attrValue) {
        $attrString .= ' ' . e($name) . '="' . e((string) $attrValue) . '"';
    }

    return '<img' . $attrString . '>';
}


/**
 * The box that stands in for an image the site does not have.
 *
 * It ships from core, so a theme need not carry a placeholder file: the theme
 * only says which shape the slot is. The caller's classes are kept, so the
 * theme still sizes and rounds the box, and the alt text is dropped because
 * there is no image to describe.
 */
function image_placeholder(string $ratio, array $attrs = []): string
{
    unset($attrs['alt']);

    $attrs['class']       = trim((string) ($attrs['class'] ?? '') . ' image-placeholder');
    $attrs['style']       = 'aspect-ratio: ' . $ratio . ';' . (string) ($attrs['style'] ?? '');
    $attrs['aria-hidden'] = 'true';

    $attrString = '';
    foreach ($attrs as $name => $attrValue) {
        $attrString .= ' ' . e($name) . '="' . e((string) $attrValue) . '"';
    }

    return '<span' . $attrString . '></span>';
}


/**
 * The image placeholder's CSS.
 *
 * render_page() ships it on every page, before the theme's stylesheets: a
 * theme need not carry the fallback, and can still restyle it.
 */
function image_placeholder_css(): string
{
    return <<<'CSS'
.image-placeholder {
    display: block;
    width: 100%;
    background-color: #e9ecef;
    background-image: repeating-linear-gradient(45deg, rgba(0, 0, 0, 0.04) 0 6px, transparent 6px 12px);
}
CSS;
}


/**
 * A <picture> element (WebP source + LQIP background) for an uploaded image.
 * Returns '' for missing rows, non-images, or rows without variants.
 */
function picture(int $id, array $attrs = []): string
{
    $media = media_by_id($id);

    if (!$media) {
        return '';
    }

    if (!media_is_image($media)) {
        debug_log("picture() called with non-image media #{$id} ({$media['mime_type']}); use media_url() instead");

        return '';
    }

    $formats = media_formats($media);

    if (!$formats) {
        return '';
    }

    $width  = (int) ($media['width'] ?? 0);
    $height = (int) ($media['height'] ?? 0);
    $lqip   = (string) ($media['lqip_base64'] ?? '');

    /**
     * Variant paths -> [width => URL], ordered by width.
     */
    $buildSrcset = function (array $paths) use ($width): array {
        $items = [];

        foreach ($paths as $path) {
            if (preg_match('/-(\d+)\.[a-z0-9]+$/i', $path, $matches)) {
                $items[(int) $matches[1]] = url('media/' . $path);
            } else {
                // No width token in the filename: key it by the original width.
                $items[$width ?: 0] = url('media/' . $path);
            }
        }

        ksort($items);

        return $items;
    };

    // Fallback format: the first non-webp entry. An upload that was already
    // webp has no other format, so its webp set is the fallback too — otherwise
    // a webp-only image would render nothing.
    $fallbackFormat = null;
    foreach (array_keys($formats) as $format) {
        if ($format !== 'webp') {
            $fallbackFormat = $format;
            break;
        }
    }

    $fallbackFormat ??= 'webp';

    $fallbackSet = $buildSrcset($formats[$fallbackFormat] ?? []);

    if (!$fallbackSet) {
        return '';
    }

    $fallbackSrc    = (string) reset($fallbackSet);
    $fallbackSrcset = implode(', ', array_map(
        fn($src, $w) => "{$src} {$w}w",
        $fallbackSet,
        array_keys($fallbackSet)
    ));

    // The webp source only adds a choice when the fallback is another format.
    $webpSet    = ($fallbackFormat === 'webp' || empty($formats['webp'])) ? [] : $buildSrcset($formats['webp']);
    $webpSrcset = implode(', ', array_map(
        fn($src, $w) => "{$src} {$w}w",
        $webpSet,
        array_keys($webpSet)
    ));

    // Defaults; callers can override any of them.
    $attrs['loading'] ??= 'lazy';
    $attrs['alt'] ??= (string) ($media['alt_text'] ?? '');

    if ($width && $height) {
        $attrs['width'] ??= $width;
        $attrs['height'] ??= $height;
    }

    $attrString = '';
    foreach ($attrs as $name => $value) {
        $attrString .= ' ' . e($name) . '="' . e((string) $value) . '"';
    }

    // Start at the smallest variant; main.js refines this to the real
    // container width once the image is on screen.
    $smallestWidth = (int) array_key_first($fallbackSet);
    $initialSizes  = $smallestWidth ? $smallestWidth . 'px' : '100vw';

    $html = '<div class="image-wrapper"';
    $html .= $lqip ? ' style="background-image:url(' . e($lqip) . ');">' : '>';
    $html .= '<picture>';

    if ($webpSet) {
        $html .= '<source type="image/webp" srcset="' . e($webpSrcset) . '" sizes="' . e($initialSizes) . '">';
    }

    $html .= '<img src="' . e($fallbackSrc) . '" srcset="' . e($fallbackSrcset) . '" sizes="' . e($initialSizes) . '"' . $attrString . '>';
    $html .= '</picture>';
    $html .= '</div>';

    return $html;
}

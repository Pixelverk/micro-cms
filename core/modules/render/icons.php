<?php

function icon(string $name, int $size = 24, string $class = ''): string
{
    $path = CMS_PATH . "/admin/assets/icons/{$name}.svg";

    if (!file_exists($path)) {
        return "<!-- Icon {$name} not found -->";
    }

    $svg = file_get_contents($path);

    // Ensure <svg> has a viewBox for scaling
    if (!preg_match('/viewBox=/i', $svg)) {
        // Default to 0 0 24 24 if missing
        $svg = preg_replace('/<svg /', '<svg viewBox="0 0 24 24" ', $svg, 1);
    }

    // Add width, height, class and hide it from assistive technology: every
    // icon-only control carries its own label.
    $svg = preg_replace(
        '/<svg /',
        "<svg width=\"{$size}\" height=\"{$size}\" class=\"{$class}\" aria-hidden=\"true\" focusable=\"false\" ",
        $svg,
        1
    );

    return $svg;
}

/**
 * The icons the theme offers, sorted.
 *
 * theme/assets/icons/ is the single source: a theme adds a glyph by adding its
 * file, and the editor's icon picker lists whatever is there. The same folder
 * the admin's own icons are read from, so a theme author has one convention to
 * learn.
 *
 * @return list<string>
 */
function theme_icons(): array
{
    $names = [];

    foreach (glob(theme('assets/icons') . '/*.svg') ?: [] as $file) {
        $names[] = basename($file, '.svg');
    }

    sort($names);

    return $names;
}

/**
 * Inline one of the theme's icons.
 *
 * The theme ships no icon webfont: each glyph lives in theme/assets/icons/ as a
 * plain SVG file and is inlined where it is used. That is a few hundred bytes
 * instead of a stylesheet listing every glyph plus the font that draws them,
 * there is nothing to flash while a font loads, and the set is whatever the
 * theme says it is rather than a fixed library.
 *
 * The icon is sized in text (1em), takes the colour of the text around it, and
 * is hidden from assistive technology: every icon here decorates a label that
 * is already on the page. A theme replaces a glyph by replacing its file.
 */
function theme_icon(string $name, string $class = 'theme-icon'): string
{
    $name = trim($name);

    // An icon name is a slug; anything else is not ours to read.
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        return '';
    }

    $path = theme('assets/icons/' . $name . '.svg');

    if (!is_file($path)) {
        return '';
    }

    // The files carry the artwork only; the presentation belongs to the markup.
    return (string) preg_replace(
        '/<svg\b/',
        '<svg width="1em" height="1em" fill="currentColor" class="' . e($class) . '" aria-hidden="true" focusable="false"',
        (string) file_get_contents($path),
        1
    );
}
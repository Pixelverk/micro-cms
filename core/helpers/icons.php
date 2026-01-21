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

    // Add width, height, and class attributes
    $svg = preg_replace(
        '/<svg /',
        "<svg width=\"{$size}\" height=\"{$size}\" class=\"{$class}\" ",
        $svg,
        1
    );

    return $svg;
}
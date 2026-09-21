<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin navigation state
|--------------------------------------------------------------------------
| Which sidebar link is "active" for the current request. Kept out of the
| sidebar partial so these names are not defined by a file included on every
| admin page.
*/

/**
 * "active" when the current page is, or is below, $path.
 */
function admin_nav_active(string $path, string $current): string
{
    return ($current === $path || str_starts_with($current, $path . '/')) ? 'active' : '';
}

/**
 * "active" when browsing (or editing) the given content type.
 */
function admin_nav_content_type_active(string $type, string $current, string $currentType): string
{
    if (!in_array($current, ['content', 'content/edit'], true)) {
        return '';
    }

    return $currentType === $type ? 'active' : '';
}

/**
 * "active" when viewing the submissions of the given form type.
 */
function admin_nav_form_type_active(string $type, string $current, array $formTypes): string
{
    if ($current !== 'messages') {
        return '';
    }

    // No ?form= means the first form type is being shown.
    $active = $_GET['form'] ?? array_key_first($formTypes);

    return $active === $type ? 'active' : '';
}

/**
 * "active" for one taxonomy's link on the shared taxonomy page.
 */
function admin_nav_taxonomy_active(string $name, string $current): string
{
    if (!in_array($current, ['taxonomy', 'taxonomy/edit'], true)) {
        return '';
    }

    // No ?type= means the first declared taxonomy is being shown.
    $active = (string) ($_GET['type'] ?? '');

    if ($active === '') {
        $active = (string) (array_key_first(theme_taxonomies()) ?? '');
    }

    return $active === $name ? 'active' : '';
}

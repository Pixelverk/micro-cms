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

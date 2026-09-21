<?php
declare(strict_types=1);




/*
|--------------------------------------------------------------------------
| Rich-text-only content types
|--------------------------------------------------------------------------
|
| A content type that declares 'editor' => 'rich-text' in theme.php is written
| as one rich-text field, not assembled from components. The stored body is
| still the usual list of components, holding exactly the one component the
| type names, so the front end, search, media usage, versions and export are
| untouched. The editor, the save handler and the Health page all resolve that
| component through here, keeping the manifest the single source of truth.
|
*/

/**
 * The rich-text component an editor-mode content type writes, or null when the
 * type is not edited as rich text or names no component with a quill field.
 *
 * @param array<string, mixed> $ctConfig one entry of theme.php's content_types
 * @return array{component: string, field: string}|null
 */
function content_rich_text_editor(array $ctConfig): ?array
{
    if (($ctConfig['editor'] ?? '') !== 'rich-text') {
        return null;
    }

    foreach ((array) ($ctConfig['available_components'] ?? []) as $name) {
        $schema = content_component_definition((string) $name)['schema'] ?? [];

        foreach ($schema as $field => $rules) {
            if (is_array($rules) && ($rules['type'] ?? '') === 'quill') {
                return ['component' => (string) $name, 'field' => (string) $field];
            }
        }
    }

    return null;
}



/**
 * The body a rich-text-only content type stores: one component of the declared
 * type, carrying only the fields its schema declares. Any other content type
 * keeps the component tree the editor posted.
 *
 * @param array<string, mixed> $ctConfig one entry of theme.php's content_types
 * @param array<int, mixed>    $body     the components the editor posted
 * @return array<int, mixed>
 */
function content_rich_text_body(array $ctConfig, array $body): array
{
    $editor = content_rich_text_editor($ctConfig);

    if ($editor === null) {
        return $body;
    }

    $definition = content_component_definition($editor['component']);
    $schema     = is_array($definition['schema'] ?? null) ? $definition['schema'] : [];

    // A rich-text save posts only the field the editor built, so its value is
    // taken by name rather than by position.
    $posted = [];

    foreach ($body as $component) {
        foreach ((array) ($component['props'] ?? []) as $field => $value) {
            $posted[$field] ??= $value;
        }
    }

    $props = [];

    foreach ($schema as $field => $rules) {
        $props[$field] = $posted[$field] ?? '';
    }

    return [['type' => $editor['component'], 'props' => $props, 'children' => []]];
}



/*
|--------------------------------------------------------------------------
| Pre-publish checklist
|--------------------------------------------------------------------------
|
| Evaluated when content is about to go live. Blocking rules stop the publish
| (the item is kept as a draft instead); the rest are warnings the editor can
| choose to ignore. Drafts are never checked, so saving work is never held up.
|
*/

/**
 * The widths a component schema field may ask for.
 *
 * A field that declares none is automatic: the editor's grid gives it its
 * natural share of the row.
 *
 * @return list<string>
 */
function content_component_field_spans(): array
{
    return ['full', 'half', 'third'];
}



/**
 * A component schema with its field widths normalised.
 *
 * The editor turns `span` into a class, so a value the stylesheet does not know
 * is dropped here — the field then behaves as if it had declared nothing. The
 * allowed values live in content_component_field_spans() so the editor, the
 * save path and the Health page all read the same list.
 *
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function content_component_field_schema(array $schema): array
{
    foreach ($schema as $name => $field) {
        if (!is_array($field) || !isset($field['span'])) {
            continue;
        }

        $span = is_string($field['span']) ? $field['span'] : '';

        if (!in_array($span, content_component_field_spans(), true)) {
            unset($schema[$name]['span']);
        }
    }

    return $schema;
}



/**
 * A component's own definition, or [] when it does not exist.
 *
 * @return array<string, mixed>
 */
function content_component_definition(string $name): array
{
    static $cache = [];

    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }

    $path = theme("components/{$name}.php");

    if (!is_file($path)) {
        $path = CORE_PATH . "/components/{$name}.php";
    }

    if (!is_file($path)) {
        return $cache[$name] = [];
    }

    $component = require $path;

    return $cache[$name] = is_array($component) ? $component : [];
}



/**
 * The URL of a component's preview image, or '' when the theme ships none.
 *
 * The convention is one folder and one name: theme/assets/previews/<component>
 * with any of the image extensions below, so a theme adds a preview by dropping
 * a file next to the others rather than by editing every component. Core
 * components look in the same folder, because it is the theme that shows them.
 */
function component_preview_url(string $name): string
{
    static $cache = [];

    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }

    $cache[$name] = '';

    // A component name is a slug; anything else is not ours to look up.
    if ($name === '' || !preg_match('/^[a-z0-9-]+$/', $name)) {
        return $cache[$name];
    }

    foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $extension) {
        $relative = "previews/{$name}.{$extension}";

        if (is_file(CMS_PATH . '/theme/assets/' . $relative)) {
            return $cache[$name] = asset($relative);
        }
    }

    return $cache[$name];
}

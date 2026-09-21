<?php
declare(strict_types=1);




/**
 * Evaluate the pre-publish checklist for an item.
 *
 * `meta` and `body` may be decoded arrays or their JSON column strings, so the
 * editor, the save path and the bulk path can each pass what they hold.
 *
 * @param array<string, mixed> $page
 * @return list<array{rule: string, level: string, ok: bool, detail: string}>
 */
function content_publish_checklist(array $page): array
{
    $body = content_checklist_decode($page['body'] ?? []);
    $meta = content_checklist_decode($page['meta'] ?? []);

    $required = [];
    $altText  = [];
    $links    = [];

    content_checklist_walk($body, $required, $altText, $links);

    return [
        [
            'rule'   => 'title',
            'level'  => 'block',
            'ok'     => trim((string) ($page['title'] ?? '')) !== '',
            'detail' => '',
        ],
        [
            'rule'   => 'required',
            'level'  => 'block',
            'ok'     => $required === [],
            'detail' => implode('; ', $required),
        ],
        [
            'rule'   => 'image_alt',
            'level'  => 'warn',
            'ok'     => $altText === [],
            'detail' => implode('; ', $altText),
        ],
        [
            'rule'   => 'links',
            'level'  => 'warn',
            'ok'     => $links === [],
            'detail' => implode('; ', $links),
        ],
        [
            'rule'   => 'description',
            'level'  => 'warn',
            'ok'     => trim((string) ($meta['description'] ?? '')) !== '',
            'detail' => '',
        ],
    ];
}



/**
 * The blocking failures in a checklist, if any.
 *
 * @param list<array{rule: string, level: string, ok: bool, detail: string}> $items
 * @return list<array{rule: string, level: string, ok: bool, detail: string}>
 */
function content_checklist_blockers(array $items): array
{
    return array_values(array_filter(
        $items,
        static fn(array $item): bool => ($item['level'] ?? '') === 'block' && empty($item['ok'])
    ));
}



/**
 * Decode a JSON column, or pass an already-decoded value through.
 *
 * @return array<string, mixed>
 */
function content_checklist_decode(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    return is_array($value) ? $value : [];
}



/**
 * Walk a component tree, collecting checklist details.
 *
 * @param array<int, mixed> $components
 * @param list<string> $required
 * @param list<string> $altText
 * @param list<string> $links
 */
function content_checklist_walk(array $components, array &$required, array &$altText, array &$links): void
{
    foreach ($components as $component) {
        if (!is_array($component)) {
            continue;
        }

        $name  = (string) ($component['type'] ?? '');
        $props = is_array($component['props'] ?? null) ? $component['props'] : [];

        if ($name !== '') {
            $definition = content_component_definition($name);
            $schema     = is_array($definition['schema'] ?? null) ? $definition['schema'] : [];
            $label      = (string) ($definition['label'] ?? $name);

            foreach ($schema as $field => $rules) {
                if (!is_array($rules)) {
                    continue;
                }

                if (($rules['required'] ?? false) && !validate_required($props[$field] ?? '')) {
                    $required[] = $label . ': ' . (string) ($rules['label'] ?? $field);
                }
            }

            // An image the theme gives a description field must describe itself.
            foreach (['image', 'img'] as $imageField) {
                $imageValue = (string) ($props[$imageField] ?? '');

                if ($imageValue === '') {
                    continue;
                }

                $altField = content_checklist_alt_field($schema, $imageField);

                if ($altField === null) {
                    continue;
                }

                if (trim((string) ($props[$altField] ?? '')) !== '') {
                    continue;
                }

                // A media library image already carries its own alt text.
                if (ctype_digit($imageValue) && trim((string) (media_by_id((int) $imageValue)['alt_text'] ?? '')) !== '') {
                    continue;
                }

                $altText[] = $label . ': ' . (string) ($schema[$altField]['label'] ?? $altField);
            }

            // A link label with no target renders a dead link.
            foreach ($schema as $field => $rules) {
                if (!is_array($rules) || !content_checklist_is_link_field($field, $rules)) {
                    continue;
                }

                if (trim((string) ($props[$field] ?? '')) !== '') {
                    continue;
                }

                if (content_checklist_link_text($schema, $props, $field) !== '') {
                    $links[] = $label . ': ' . (string) ($rules['label'] ?? $field);
                }
            }
        }

        $children = is_array($component['children'] ?? null) ? $component['children'] : [];
        content_checklist_walk($children, $required, $altText, $links);
    }
}



/**
 * The alt field a schema pairs with an image field, if any.
 *
 * @param array<string, mixed> $schema
 */
function content_checklist_alt_field(array $schema, string $imageField): ?string
{
    foreach ([$imageField . '_alt', 'alt', 'alt_text'] as $candidate) {
        if (isset($schema[$candidate])) {
            return $candidate;
        }
    }

    return null;
}



/**
 * Is this schema field a link target?
 *
 * @param array<string, mixed> $rules
 */
function content_checklist_is_link_field(string $field, array $rules): bool
{
    if (($rules['type'] ?? '') === 'url') {
        return true;
    }

    return $field === 'url' || $field === 'href' || str_ends_with($field, '_url');
}



/**
 * The visible text paired with a link target, or ''.
 *
 * Only same-prefix pairs count (`btn1_url` with `btn1_text`), plus the
 * `url`/`linktext` pair the theme uses for a call to action. A URL with no
 * label (`redirect_url`) is not a visible link, so it is ignored.
 *
 * @param array<string, mixed> $schema
 * @param array<string, mixed> $props
 */
function content_checklist_link_text(array $schema, array $props, string $field): string
{
    $candidates = [];

    if (str_ends_with($field, '_url')) {
        $prefix = substr($field, 0, -4);
        $candidates[] = $prefix . '_text';
        $candidates[] = $prefix . '_label';
    } elseif ($field === 'url') {
        $candidates[] = 'linktext';
    }

    foreach ($candidates as $candidate) {
        if (isset($schema[$candidate]) && trim((string) ($props[$candidate] ?? '')) !== '') {
            return (string) $props[$candidate];
        }
    }

    return '';
}

<?php
declare(strict_types=1);




/**
 * Collect the presentation-image meta a content type declares.
 *
 * The theme lists them under the content type's `images` key (for example a
 * `thumbnail`, or a `gallery` with `multiple => true`). Values are media ids,
 * theme filenames or absolute URLs, exactly like resolve_image_value().
 *
 * A field the form did not submit is left untouched, so partial saves keep it.
 * A `multiple` field submits an array; the empty sentinel row the editor
 * renders means "remove them all" reaches this function as an array of blanks.
 *
 * @param array<string, mixed> $post
 * @param array<string, mixed> $meta
 * @param array<string, array<string, mixed>> $fields
 * @return array<string, mixed>
 */
function content_collect_images(array $post, array $meta, array $fields): array
{
    foreach ($fields as $key => $field) {
        $value = $post['meta_' . $key] ?? null;

        if ($value === null) {
            continue;
        }

        if (!empty($field['multiple'])) {
            $values = array_values(array_filter(array_map(
                static fn($item): string => is_string($item) ? trim($item) : '',
                is_array($value) ? $value : []
            ), static fn(string $item): bool => $item !== ''));

            if ($values === []) {
                unset($meta[$key]);
            } else {
                $meta[$key] = $values;
            }

            continue;
        }

        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            unset($meta[$key]);
        } else {
            $meta[$key] = $value;
        }
    }

    return $meta;
}



/**
 * The meta fields a content type declares for the editor, normalised.
 *
 * The theme lists them under the content type's `fields` key — its own keys
 * stored in the item's meta array, which a layout then reads as
 * `$page['meta'][key]`. This is separate from `images`, which keeps the
 * presentation images in their own card.
 *
 * A declaration uses the same vocabulary as a component schema's fields, so a
 * theme author learns one set of names. Supported: text, textarea, url, email,
 * number, checkbox, select (with `options`) and media (one image, chosen with
 * the media picker). An entry with no `type` is text.
 *
 * @param array<string, mixed> $ctConfig one entry of theme.php's content_types
 * @return array<string, array<string, mixed>> key => field
 */
function content_meta_fields(array $ctConfig): array
{
    $fields = is_array($ctConfig['fields'] ?? null) ? $ctConfig['fields'] : [];

    $normalised = [];

    foreach ($fields as $key => $field) {
        $field = is_array($field) ? $field : [];

        if (!isset($field['type']) || $field['type'] === '') {
            $field['type'] = 'text';
        }

        if (!isset($field['label']) || $field['label'] === '') {
            $field['label'] = ucfirst(str_replace('_', ' ', (string) $key));
        }

        $normalised[(string) $key] = $field;
    }

    return $normalised;
}



/**
 * What a declared field currently holds, as a string for the form: the saved
 * value, else the field's default, and '' when neither is set.
 */
function content_meta_field_value(array $field, mixed $value): string
{
    if (is_array($value)) {
        return '';
    }

    $value = trim((string) $value);

    return $value !== '' ? $value : trim((string) ($field['default'] ?? ''));
}



/**
 * Collect the meta fields a content type declares from the submitted form.
 *
 * A field the form did not submit is left untouched, so partial saves keep it.
 * A blank value unsets the key rather than storing an empty string. Values are
 * trimmed and capped at the field's `max`; checked checkboxes are read as true
 * and unchecked ones (which a browser submits as an empty value) as false.
 * Whether a value is a valid url, email, number or select choice is the save
 * handler's business — this only shapes what was posted.
 *
 * @param array<string, mixed> $post
 * @param array<string, mixed> $meta
 * @param array<string, array<string, mixed>> $fields  content_meta_fields()
 * @return array<string, mixed>
 */
function content_collect_meta_fields(array $post, array $meta, array $fields): array
{
    foreach ($fields as $key => $field) {
        $value = $post['meta_' . $key] ?? null;

        if ($value === null) {
            continue;
        }

        if (($field['type'] ?? 'text') === 'checkbox') {
            $meta[$key] = validate_required($value);
            continue;
        }

        $value = is_scalar($value) ? trim((string) $value) : '';

        $max = (int) ($field['max'] ?? 0);

        if ($max > 0 && mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }

        if ($value === '') {
            unset($meta[$key]);
        } else {
            $meta[$key] = $value;
        }
    }

    return $meta;
}



/**
 * The reason a submitted meta field is not acceptable, or '' when it is.
 *
 * The value passed is what was stored, so a field that was left blank passes:
 * only meta a theme asks for and an editor typed is judged.
 *
 * @param array<string, mixed> $field
 */
function content_meta_field_error(array $field, mixed $value): string
{
    $type  = (string) ($field['type'] ?? 'text');
    $label = (string) ($field['label'] ?? '');

    if ($type === 'checkbox') {
        return '';
    }

    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    return match ($type) {
        'url'    => validate_url($value) ? '' : admin_trans('content_error_meta_url', ['label' => $label]),
        'email'  => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? admin_trans('content_error_meta_email', ['label' => $label]) : '',
        'number' => is_numeric($value) ? '' : admin_trans('content_error_meta_number', ['label' => $label]),
        'select' => array_key_exists($value, is_array($field['options'] ?? null) ? $field['options'] : []) ? '' : admin_trans('content_error_meta_select', ['label' => $label]),
        default  => '',
    };
}

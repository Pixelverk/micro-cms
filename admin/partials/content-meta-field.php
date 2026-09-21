<?php
/*
|--------------------------------------------------------------------------
| One content type meta field
|--------------------------------------------------------------------------
|
| Renders a field a content type declares under its `fields` key in theme.php,
| as admin/content/edit.php's Details card does. Expects:
|
|   $metaFieldKey   the meta key it is stored under
|   $metaField      content_meta_fields()'s normalised declaration
|   $metaFieldValue the current value, from content_meta_field_value()
|
| Types, and what they render: text, url, email and number as inputs;
| textarea; select with the declared options; checkbox, which reads as one line
| with its label beside the box; and media, the same media-library picker a
| component image field uses. admin/assets/content-editor.js wires the media
| pickers up.
*/

$metaFieldKey   = (string) $metaFieldKey;
$metaField      = is_array($metaField) ? $metaField : [];
$metaFieldValue = (string) $metaFieldValue;
$metaFieldType  = (string) ($metaField['type'] ?? 'text');
$metaFieldLabel = (string) ($metaField['label'] ?? $metaFieldKey);
$metaFieldId    = 'meta-' . $metaFieldKey;
$metaFieldName  = 'meta_' . $metaFieldKey;
$metaFieldMax   = (int) ($metaField['max'] ?? 0);

// A checkbox reads as one line of text, so its label sits beside the box in
// .field-check rather than above it like every other field's.
if ($metaFieldType === 'checkbox'): ?>
    <label class="field field-check" for="<?= e($metaFieldId) ?>">
        <input type="checkbox" id="<?= e($metaFieldId) ?>" name="<?= e($metaFieldName) ?>" value="1"
            <?= $metaFieldValue !== '' && $metaFieldValue !== '0' ? 'checked' : '' ?>>
        <span class="field-label"><?= e($metaFieldLabel) ?></span>
        <?php if (!empty($metaField['help'])): ?>
            <small><?= e((string) $metaField['help']) ?></small>
        <?php endif; ?>
    </label>
<?php else: ?>
<label class="field" for="<?= e($metaFieldId) ?>">
    <span class="field-label"><?= e($metaFieldLabel) ?></span>

    <?php if ($metaFieldType === 'textarea'): ?>
        <textarea class="field-input" id="<?= e($metaFieldId) ?>" name="<?= e($metaFieldName) ?>"
            rows="3" <?= $metaFieldMax > 0 ? 'maxlength="' . $metaFieldMax . '"' : '' ?>
        ><?= e($metaFieldValue) ?></textarea>

    <?php elseif ($metaFieldType === 'select'): ?>
        <select class="field-input" id="<?= e($metaFieldId) ?>" name="<?= e($metaFieldName) ?>">
            <?php if (empty($metaField['required'])): ?>
                <option value=""><?= e(admin_trans('common_none')) ?></option>
            <?php endif; ?>

            <?php foreach ((array) ($metaField['options'] ?? []) as $optionValue => $optionLabel): ?>
                <option value="<?= e((string) $optionValue) ?>" <?= ((string) $optionValue === $metaFieldValue) ? 'selected' : '' ?>>
                    <?= e((string) $optionLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>

    <?php elseif ($metaFieldType === 'media'): ?>
        <div class="image-picker-wrapper">
            <input class="field-input" type="text" id="<?= e($metaFieldId) ?>" name="<?= e($metaFieldName) ?>"
                value="<?= e($metaFieldValue) ?>" data-image-picker>
            <img class="image-preview" alt="<?= e(admin_trans('media_no_image')) ?>">
            <div class="image-picker-actions">
                <button type="button" class="select-image-btn"><?= e(admin_trans('media_select_image')) ?></button>
                <button type="button" class="clear-image-btn"><?= e(admin_trans('common_clear')) ?></button>
            </div>
        </div>

    <?php else: ?>
        <?php $metaFieldInputType = in_array($metaFieldType, ['url', 'email', 'number'], true) ? $metaFieldType : 'text'; ?>
        <input class="field-input" type="<?= e($metaFieldInputType) ?>" id="<?= e($metaFieldId) ?>" name="<?= e($metaFieldName) ?>"
            value="<?= e($metaFieldValue) ?>" <?= $metaFieldMax > 0 ? 'maxlength="' . $metaFieldMax . '"' : '' ?>>
    <?php endif; ?>

    <?php if (!empty($metaField['help'])): ?>
        <small><?= e((string) $metaField['help']) ?></small>
    <?php endif; ?>
</label>
<?php endif; ?>

<!-- Component container -->
<template id="component-template">
    <fieldset class="component">
        <details>
            <summary class="component-title"></summary>

            <input type="hidden" class="component-type">

            <div class="component-fields"></div>

            <div class="children-container flex gap-lg"></div>

            <div class="component-actions">
                <div class="actions-left">
                    <select class="allowed-children-select" name="allowed-children-select">
                        <option value=""><?= e(admin_trans('editor_child_component')) ?></option>
                    </select>
                    <p class="no-children text-small"><?= e(admin_trans('editor_no_child_components')) ?></p>
                    <button type="button" class="add-child-btn"><?= e(admin_trans('common_add')) ?></button>
                </div>
                <div class="actions-right">
                    <button type="button" class="move-up" title="<?= e(admin_trans('editor_move_up')) ?>">
                        &#8593;
                        <span class="off-screen"><?= e(admin_trans('editor_move_up')) ?></span>
                    </button>
                    <button type="button" class="move-down" title="<?= e(admin_trans('editor_move_down')) ?>">
                        &#8595;
                        <span class="off-screen"><?= e(admin_trans('editor_move_down')) ?></span>
                    </button>
                    <button type="button" class="duplicate-btn" title="<?= e(admin_trans('editor_duplicate')) ?>">
                        &#9868;
                        <span class="off-screen"><?= e(admin_trans('editor_duplicate')) ?></span>
                    </button>
                    <button type="button" class="remove-btn" title="<?= e(admin_trans('common_remove')) ?>">
                        &#33;
                        <span class="off-screen"><?= e(admin_trans('common_remove')) ?></span>
                    </button>
                </div>
            </div>
        </details>
    </fieldset>
</template>

<!-- Text input field (default) -->
<template id="field-template">
    <label class="field">
        <span class="field-label"></span>
        <input class="field-input" type="text">
    </label>
</template>

<!-- Textarea input field -->
<template id="textarea-template">
    <label class="field">
        <span class="field-label"></span>
        <textarea class="field-input"></textarea>
    </label>
</template>

<!-- Number field -->
<template id="number-template">
    <label class="field">
        <span class="field-label"></span>
        <input class="field-input" type="number">
    </label>
</template>

<!-- Color picker -->
<template id="color-template">
    <label class="field">
        <span class="field-label"></span>
        <input class="field-input" type="color">
    </label>
</template>

<!-- Checkbox -->
<template id="checkbox-template">
    <label class="field">
        <input class="field-input" type="checkbox">
        <span class="field-label"></span>
    </label>
</template>

<!-- URL -->
<template id="url-template">
    <label class="field">
        <span class="field-label"></span>
        <input class="field-input" type="url">
    </label>
</template>

<!-- Email -->
<template id="email-template">
    <label class="field">
        <span class="field-label"></span>
        <input class="field-input" type="email">
    </label>
</template>

<!-- Quill Editor -->
<template id="quill-editor-template">
    <div class="field">
        <span class="field-label"></span>
        <input type="hidden" class="field-input quill-hidden">  <!-- the actual thing submitted with form -->
        <div class="quill-editor"><?= e(admin_trans('editor_js_placeholder')) ?></div> <!-- the visual input for the user -->
    </div>
</template>

<!-- Select dropdown -->
<template id="select-template">
    <label class="field">
        <span class="field-label"></span>
        <select class="field-input"></select>
    </label>
</template>

<!-- Icon picker field -->
<template id="icon-template">
    <label class="field">
        <span class="field-label"></span>
        <div class="icon-picker-wrapper">
            <!-- Stores the icon's name; the browser writes it -->
            <input type="hidden" class="field-input" data-icon-picker>

            <!-- The chosen glyph, copied out of the browser's own markup -->
            <span class="icon-preview" aria-hidden="true"></span>
            <span class="icon-name"></span>

            <div class="icon-picker-actions">
                <button type="button" class="select-icon-btn"><?= e(admin_trans('editor_select_icon')) ?></button>
                <button type="button" class="clear-icon-btn"><?= e(admin_trans('common_clear')) ?></button>
            </div>
        </div>
    </label>
</template>

<!-- Image picker field -->
<template id="image-template">
    <label class="field">
        <span class="field-label"></span>
        <div class="image-picker-wrapper">
            <!-- Hidden input stores the DB ID of the selected media -->
            <input type="hidden" class="field-input" data-image-picker>

            <!-- Preview; without a src the alt text is the empty state -->
            <img class="image-preview" alt="<?= e(admin_trans('media_no_image')) ?>">

            <div class="image-picker-actions">
                <button type="button" class="select-image-btn"><?= e(admin_trans('media_select_image')) ?></button>
                <button type="button" class="clear-image-btn"><?= e(admin_trans('common_clear')) ?></button>
            </div>
        </div>
    </label>
</template>

<!-- One row of a repeatable gallery meta field; the name is set when it is added -->
<template id="gallery-row-template">
    <div class="gallery-row">
        <div class="image-picker-wrapper">
            <input class="field-input" type="text" data-image-picker>
            <img class="image-preview" alt="<?= e(admin_trans('media_no_image')) ?>">
            <div class="image-picker-actions">
                <!-- The row's own Remove control is this image's clear action. -->
                <button type="button" class="select-image-btn"><?= e(admin_trans('media_select_image')) ?></button>
            </div>
        </div>
        <button type="button" class="btn btn-small btn-muted remove-gallery-image"><?= e(admin_trans('editor_gallery_remove')) ?></button>
    </div>
</template>
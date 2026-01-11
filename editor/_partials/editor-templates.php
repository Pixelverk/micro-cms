<!-- Component container -->
<template id="component-template">
    <fieldset class="component">
        <legend class="component-title"></legend>

        <input type="hidden" class="component-type">

        <div class="component-fields"></div>

        <div class="children-container"></div>

        <div class="component-actions">
            <div class="children-select">
                <select class="allowed-children-select" style="display:none;">
                    <option value="">-- Child Component --</option>
                </select>
                <button type="button" class="add-child-btn" style="display:none;">Add</button>
            </div>
            <div>
                <button type="button" class="move-up">&#8593;</button>
                <button type="button" class="move-down">&#8595;</button>
                <button type="button" class="duplicate-btn">&#9868;</button>
                <button type="button" class="remove-btn">&#33;</button>
            </div>
        </div>
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
<template id="menu-item-template">
    <fieldset class="menu-item" style="border:1px solid #ccc; padding:0.5rem; margin-bottom:0.5rem;">
        <legend class="menu-item-title" style="cursor:move; font-weight:bold; margin-bottom:0.5rem;">
            Menu Item
        </legend>

        <div class="menu-fields" style="display:flex; flex-wrap:wrap; gap:1rem;">
            <label style="flex:1 1 150px;">
                Label
                <input type="text" class="field-input" data-field="label" placeholder="Label">
            </label>

            <label style="flex:1 1 120px;">
                Type
                <select class="field-input" data-field="type">
                    <option value="page">Page</option>
                    <option value="url">URL</option>
                </select>
            </label>

            <label style="flex:1 1 200px;">
                Slug / URL
                <input type="text" class="field-input" data-field="slug" placeholder="slug or URL">
            </label>

            <label style="flex:1 1 120px;">
                Target
                <select class="field-input" data-field="target">
                    <option value="_self">Same tab</option>
                    <option value="_blank">New tab</option>
                </select>
            </label>
        </div>

        <div class="children-container" style="margin-left:1rem; margin-top:0.5rem;"></div>

        <div class="menu-actions" style="margin-top:0.5rem; display:flex; gap:0.25rem;">
            <button type="button" class="add-child">+ Child</button>
            <button type="button" class="duplicate">⧉ Duplicate</button>
            <button type="button" class="remove">✕ Remove</button>
        </div>
    </fieldset>
</template>
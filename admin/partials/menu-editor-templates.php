<template id="menu-item-template">
    <fieldset class="menu-item" style="border:1px solid #ccc; padding:0.5rem; margin-bottom:0.5rem;">
        <legend class="menu-item-title" style="cursor:move; font-weight:bold; margin-bottom:0.5rem;">
            <?= e(admin_trans('menu_item')) ?>
        </legend>

        <div class="menu-fields" style="display:flex; flex-wrap:wrap; gap:1rem;">
            <label style="flex:1 1 150px;">
                <?= e(admin_trans('label')) ?>
                <input type="text" class="field-input" data-field="label" placeholder="<?= e(admin_trans('label')) ?>">
            </label>

            <label style="flex:1 1 120px;">
                <?= e(admin_trans('type')) ?>
                <select class="field-input" data-field="type">
                    <option value="page"><?= e(admin_trans('page')) ?></option>
                    <option value="url"><?= e(admin_trans('url')) ?></option>
                </select>
            </label>

            <label style="flex:1 1 200px;">
                <?= e(admin_trans('slug_or_url')) ?>
                <input type="text" class="field-input" data-field="slug" placeholder="<?= e(admin_trans('slug_or_url')) ?>">
            </label>

            <label style="flex:1 1 120px;">
                <?= e(admin_trans('target')) ?>
                <select class="field-input" data-field="target">
                    <option value="_self"><?= e(admin_trans('same_tab')) ?></option>
                    <option value="_blank"><?= e(admin_trans('new_tab')) ?></option>
                </select>
            </label>
        </div>

        <div class="children-container" style="margin-left:1rem; margin-top:0.5rem;"></div>

        <div class="menu-actions" style="margin-top:0.5rem; display:flex; gap:0.25rem;">
            <button type="button" class="add-child"><?= e(admin_trans('child')) ?></button>
            <button type="button" class="duplicate"><?= e(admin_trans('duplicate')) ?></button>
            <button type="button" class="remove"><?= e(admin_trans('remove')) ?></button>
        </div>
    </fieldset>
</template>
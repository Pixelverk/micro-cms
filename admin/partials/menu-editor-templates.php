<template id="menu-item-template">
    <fieldset class="menu-item">
        <legend class="menu-item-title">
            <?= e(admin_trans('menu_item')) ?>
        </legend>

        <div class="menu-fields">
            <label class="menu-field menu-field-label">
                <?= e(admin_trans('common_label')) ?>
                <input type="text" class="field-input" data-field="label" placeholder="<?= e(admin_trans('common_label')) ?>">
            </label>

            <label class="menu-field menu-field-narrow">
                <?= e(admin_trans('common_type')) ?>
                <select class="field-input" data-field="type">
                    <option value="page"><?= e(admin_trans('common_page')) ?></option>
                    <option value="url"><?= e(admin_trans('common_url')) ?></option>
                </select>
            </label>

            <label class="menu-field menu-field-wide">
                <?= e(admin_trans('menu_slug_or_url')) ?>
                <input type="text" class="field-input" data-field="slug" placeholder="<?= e(admin_trans('menu_slug_or_url')) ?>">
            </label>

            <label class="menu-field menu-field-narrow">
                <?= e(admin_trans('menu_target')) ?>
                <select class="field-input" data-field="target">
                    <option value="_self"><?= e(admin_trans('menu_target_same')) ?></option>
                    <option value="_blank"><?= e(admin_trans('menu_target_new')) ?></option>
                </select>
            </label>
        </div>

        <div class="children-container menu-children"></div>

        <div class="menu-actions">
            <button type="button" class="add-child"><?= e(admin_trans('editor_child')) ?></button>
            <button type="button" class="duplicate"><?= e(admin_trans('editor_duplicate')) ?></button>
            <button type="button" class="remove"><?= e(admin_trans('common_remove')) ?></button>
        </div>
    </fieldset>
</template>
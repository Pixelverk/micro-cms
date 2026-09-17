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
            <button type="button" class="add-child" title="<?= e(admin_trans('editor_child')) ?>">
                <?= icon('corner-down-right', 16) ?>
                <span class="off-screen"><?= e(admin_trans('editor_child')) ?></span>
            </button>
            <button type="button" class="duplicate" title="<?= e(admin_trans('editor_duplicate')) ?>">
                <?= icon('copy', 16) ?>
                <span class="off-screen"><?= e(admin_trans('editor_duplicate')) ?></span>
            </button>
            <button type="button" class="remove" title="<?= e(admin_trans('common_remove')) ?>">
                <?= icon('xmark', 16) ?>
                <span class="off-screen"><?= e(admin_trans('common_remove')) ?></span>
            </button>
        </div>
    </fieldset>
</template>
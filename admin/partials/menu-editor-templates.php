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

            <label class="menu-field menu-field-wide">
                <?= e(admin_trans('menu_link')) ?>
                <?php /* A custom link is typed; a content or archive link is resolved,
                         so the stored slug stays hidden and the row shows where the
                         item really points. */ ?>
                <input type="text" class="field-input" data-field="slug" placeholder="<?= e(admin_trans('menu_slug_or_url')) ?>">
                <span class="menu-link-value" data-link-value></span>
            </label>

            <label class="menu-field menu-field-narrow">
                <?= e(admin_trans('menu_target')) ?>
                <select class="field-input" data-field="target">
                    <option value="_self"><?= e(admin_trans('menu_target_same')) ?></option>
                    <option value="_blank"><?= e(admin_trans('menu_target_new')) ?></option>
                </select>
            </label>

            <input type="hidden" class="field-input" data-field="type" value="url">
            <input type="hidden" class="field-input" data-field="content_id" value="">
        </div>

        <label class="field-check menu-item-hidden">
            <input type="checkbox" class="field-input" data-field="hidden">
            <?= e(admin_trans('menu_hidden')) ?>
        </label>

        <p class="menu-item-warning" data-item-warning hidden></p>

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

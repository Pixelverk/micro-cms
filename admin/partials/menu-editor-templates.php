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

        <p class="menu-item-warning" data-item-warning hidden></p>

        <?php /* Shown when an ancestor is hidden: this row is not hidden itself,
                 but it leaves the site with the branch it belongs to. */ ?>
        <p class="menu-item-note" data-item-note hidden></p>

        <div class="children-container menu-children"></div>

        <div class="menu-actions">
            <?php /* The hidden switch reads as a fourth icon button: an eye while
                     the item is on the site, a struck-through one while it is not.
                     It stays a checkbox underneath, so the form posts it and the
                     row's own state class drives which icon is shown. */ ?>
            <label class="menu-hidden-toggle" title="<?= e(admin_trans('menu_hidden')) ?>">
                <input type="checkbox" class="field-input" data-field="hidden">
                <span class="off-screen"><?= e(admin_trans('menu_hidden')) ?></span>
                <span class="menu-hidden-eye" aria-hidden="true">
                    <?= icon('eye', 16) ?>
                    <?= icon('eye-slash', 16) ?>
                </span>
            </label>

            <div class="menu-action-buttons">
                <button type="button" class="add-child btn-small btn-icon" title="<?= e(admin_trans('editor_child')) ?>">
                    <?= icon('corner-down-right', 16) ?>
                    <span class="off-screen"><?= e(admin_trans('editor_child')) ?></span>
                </button>
                <button type="button" class="duplicate btn-small btn-icon" title="<?= e(admin_trans('editor_duplicate')) ?>">
                    <?= icon('copy', 16) ?>
                    <span class="off-screen"><?= e(admin_trans('editor_duplicate')) ?></span>
                </button>
                <button type="button" class="remove btn-delete btn-small btn-icon" title="<?= e(admin_trans('common_remove')) ?>">
                    <?= icon('trash', 16) ?>
                    <span class="off-screen"><?= e(admin_trans('common_remove')) ?></span>
                </button>
            </div>
        </div>
    </fieldset>
</template>

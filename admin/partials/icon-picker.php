<!-- Icon Picker Modal -->
<?php
/*
|--------------------------------------------------------------------------
| Icon browser
|--------------------------------------------------------------------------
| Every icon the theme ships in theme/assets/icons/, as tiles the editor picks
| from. Each tile is the inline SVG itself, so the browser shows exactly what
| the page will draw and the field's preview copies that same markup rather
| than fetching anything.
|--------------------------------------------------------------------------
*/
?>
<div id="icon-picker" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="icon-picker-title" hidden>
    <div class="modal modal-lg" tabindex="-1">
        <div class="modal-header">
            <h3 id="icon-picker-title"><?= e(admin_trans('editor_select_icon')) ?></h3>
            <button type="button" class="close-modal" aria-label="<?= e(admin_trans('common_close')) ?>">&times;</button>
        </div>
        <div class="modal-body">
            <p class="field-note"><?= e(admin_trans('editor_select_icon_help')) ?></p>

            <div class="icon-picker-grid">
                <?php foreach (theme_icons() as $iconName): ?>
                    <button type="button" class="icon-tile" data-icon="<?= e($iconName) ?>" title="<?= e($iconName) ?>">
                        <?= theme_icon($iconName, 'icon-tile-glyph') ?>
                        <span class="icon-tile-name"><?= e($iconName) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

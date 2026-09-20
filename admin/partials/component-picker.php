<!-- Component Picker Modal -->
<?php
/*
|--------------------------------------------------------------------------
| Add component dialog
|--------------------------------------------------------------------------
| The components this content type offers, as tiles the editor can pick from.
| Expects $availableComponents from admin/content/edit.php, the same list the
| editor's schema comes from, so the dialog can never drift from the palette.
|
| A tile shows the component's label, its one-line description when it has one,
| and its preview from theme/assets/previews/<component> — the neutral tile
| when the theme ships none.
|--------------------------------------------------------------------------
*/
?>
<div id="component-picker" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="component-picker-title" hidden>
    <div class="modal modal-lg" tabindex="-1">
        <div class="modal-header">
            <h3 id="component-picker-title"><?= e(admin_trans('editor_add_component')) ?></h3>
            <button type="button" class="close-modal" aria-label="<?= e(admin_trans('common_close')) ?>">&times;</button>
        </div>
        <div class="modal-body">
            <p class="field-note"><?= e(admin_trans('editor_add_component_help')) ?></p>

            <div class="component-picker-grid">
                <?php foreach ($availableComponents as $name => $availableComponent): ?>
                    <button type="button" class="component-tile" data-component-type="<?= e($name) ?>">
                        <span class="component-tile-preview">
                            <?php if ($availableComponent['preview'] !== ''): ?>
                                <img src="<?= e($availableComponent['preview']) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                        </span>
                        <span class="component-tile-label"><?= e($availableComponent['label']) ?></span>
                        <?php if ($availableComponent['description'] !== ''): ?>
                            <span class="component-tile-description"><?= e($availableComponent['description']) ?></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

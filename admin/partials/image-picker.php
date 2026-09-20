<!-- Image Picker Modal -->
<div id="image-picker-modal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="image-picker-title" hidden>
    <div class="modal" tabindex="-1">
        <div class="modal-header">
            <h3 id="image-picker-title"><?= e(admin_trans('media_select_image')) ?></h3>
            <button type="button" class="close-modal" aria-label="<?= e(admin_trans('common_close')) ?>">&times;</button>
        </div>
        <div class="modal-body">
            <input type="text" id="image-search" class="image-search" aria-label="<?= e(admin_trans('media_search_images')) ?>" placeholder="<?= e(admin_trans('media_search_images')) ?>">
            <div id="image-grid" class="image-grid"></div>
        </div>
    </div>
</div>


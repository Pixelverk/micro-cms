<!-- Image Picker Modal -->
<div id="image-picker-modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <h3><?= e(admin_trans('media_select_image')) ?></h3>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <input type="text" id="image-search" class="image-search" placeholder="<?= e(admin_trans('media_search_images')) ?>">
            <div id="image-grid" class="image-grid"></div>
        </div>
    </div>
</div>


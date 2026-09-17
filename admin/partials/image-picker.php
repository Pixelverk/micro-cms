<!-- Image Picker Modal -->
<div id="image-picker-modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <h3><?= e(admin_trans('media_select_image')) ?></h3>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <input type="text" id="image-search" placeholder="<?= e(admin_trans('media_search_images')) ?>" style="width:100%; margin-bottom:0.5rem; padding:0.25rem;">
            <div id="image-grid" class="image-grid"></div>
        </div>
    </div>
</div>


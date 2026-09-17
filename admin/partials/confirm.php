<div id="confirm-modal" class="modal-backdrop" hidden>
    <div class="modal">
        <h3 id="confirm-title"><?= e(admin_trans('common_confirm_action')) ?></h3>
        <p id="confirm-message"><?= e(admin_trans('common_are_you_sure')) ?></p>

        <div class="modal-actions">
            <button type="button" id="confirm-cancel" class="btn-muted">
                <?= e(admin_trans('common_cancel')) ?>
            </button>
            <button type="button" id="confirm-ok" class="btn-danger">
                <?= e(admin_trans('common_confirm')) ?>
            </button>
        </div>
    </div>
</div>
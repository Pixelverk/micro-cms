<!-- Image Picker Modal -->
<div id="image-picker-modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <h3>Select Image</h3>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <input type="text" id="image-search" placeholder="Search images…" style="width:100%; margin-bottom:0.5rem; padding:0.25rem;">
            <div id="image-grid" class="image-grid"></div>
        </div>
    </div>
</div>

<style>
.modal.hidden { display:none; }
.modal-backdrop { position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); }
.modal-content { position:relative; background:#fff; padding:1rem; border-radius:6px; max-width:600px; width:90%; max-height:80%; overflow-y:auto; z-index:1; }
.modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem; }
.modal-header h3 { margin:0; }
.image-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap:0.5rem; }
.image-grid img { width:100%; height:80px; object-fit:cover; border-radius:4px; cursor:pointer; border:2px solid transparent; }
.image-grid img.selected { border-color:#4f46e5; }
</style>
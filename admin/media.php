<?php
// admin/media.php

$pageTitle = 'Media Manager';
$username  = $_SESSION['user_id'] ?? 'User';

$pdo = db();

// ----------------------------
// Search
// ----------------------------
$search = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM media";
$params = [];

if ($search !== '') {
    $sql .= " WHERE original_name LIKE :q OR alt_text LIKE :q OR description LIKE :q";
    $params['q'] = "%{$search}%";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mediaFiles = [];

foreach ($rows as $row) {
    $formats = json_decode($row['formats_json'], true) ?? [];
    $sizes   = json_decode($row['sizes_json'] ?? '{}', true) ?? [];

    // Choose preview for grid
    $previewUrl = null;
    if (!empty($formats['webp'])) {
        $previewUrl = '/media/' . $formats['webp'][0];
    } elseif (!empty($formats)) {
        $firstFormat = reset($formats);
        if (!empty($firstFormat)) $previewUrl = '/media/' . $firstFormat[0];
    }

    $mediaFiles[] = [
        'id'            => $row['id'],
        'base_path'     => $row['base_path'],
        'preview_url'   => $previewUrl,
        'original_name' => $row['original_name'],
        'mime'          => $row['mime_type'],
        'size'          => round((int)$row['original_size'] / 1024, 1) . ' KB',
        'alt'           => $row['alt_text'] ?? '',
        'description'   => $row['description'] ?? '',
        'created_at'    => date('Y-m-d H:i', (int)$row['created_at']),
        'formats'       => $formats,
        'sizes'         => $sizes,
    ];
}

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Media Manager</h2>
        <p>Hello, <?= e($username) ?> 👋</p>
    </div>

    <div class="page-actions" style="display:flex; gap:1rem; align-items:center;">
        <!-- Search -->
        <form method="get">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search files…">
        </form>

        <!-- Upload -->
        <form action="<?= url('admin/media-save') ?>" method="post" enctype="multipart/form-data">
            <input type="file" name="file" required>
            <button type="submit">Upload</button>
        </form>
    </div>
</div>

<?php if (!$mediaFiles): ?>
    <p>No media uploaded yet.</p>
<?php else: ?>

<div class="media-layout">

    <!-- Grid -->
    <div class="media-grid" id="media-grid">

        <?php foreach ($mediaFiles as $file): ?>
            <div
                class="media-item"
                data-id="<?= (int)$file['id'] ?>"
                data-base-path="<?= e($file['base_path']) ?>"
                data-preview-url="<?= e($file['preview_url']) ?>"
                data-name="<?= e($file['original_name']) ?>"
                data-alt="<?= e($file['alt']) ?>"
                data-description="<?= e($file['description']) ?>"
                data-mime="<?= e($file['mime']) ?>"
                data-size="<?= e($file['size']) ?>"
                data-time="<?= e($file['created_at']) ?>"
                data-formats='<?= json_encode($file['formats'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                data-sizes='<?= json_encode($file['sizes'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
            >
                <?php if ($file['preview_url'] && str_starts_with($file['mime'], 'image/')): ?>
                    <picture>
                        <?php if (!empty($file['formats']['webp'])): ?>
                            <source type="image/webp" srcset="<?= e(url('media/' . $file['formats']['webp'][0])) ?>">
                        <?php endif; ?>
                        <img src="<?= e(url('media/' . ($file['formats']['jpg'][0] ?? $file['preview_url']))) ?>" loading="lazy" alt="<?= e($file['alt']) ?>">
                    </picture>
                <?php else: ?>
                    <div class="media-file"><?= e($file['original_name']) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

    </div>

    <!-- Inspector panel -->
    <div class="media-inspector" id="inspector">
        <p>Select a file…</p>
    </div>

</div>

<?php endif; ?>


<style>
.media-layout { display:flex; gap:2rem; }
.media-grid { flex:1; display:grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap:1rem; }
.media-item { cursor:pointer; border:2px solid transparent; height:fit-content; }
.media-item.selected { border-color:#4f46e5; }
.media-item img,
.media-file { width:100%; height:120px; object-fit:cover; border-radius:6px; background:#f2f2f2; display:flex; align-items:center; justify-content:center; }
.media-inspector { width:320px; border-left:1px solid #ddd; padding-left:1rem; }
.media-inspector img { width:100%; margin-bottom:1rem; }
.media-inspector label { display:block; margin-bottom:.75rem; }
.media-inspector input,
.media-inspector textarea,
.media-inspector select { width:100%; padding:.25rem; margin-top:.25rem; }
</style>

<script>
const inspector = document.getElementById('inspector');
const items = document.querySelectorAll('.media-item');

items.forEach(item => {
    item.addEventListener('click', () => {

        items.forEach(i => i.classList.remove('selected'));
        item.classList.add('selected');

        const data = item.dataset;
        const formats = JSON.parse(data.formats);
        const sizes   = JSON.parse(data.sizes || '{}');

        // Build size dropdown
        let optionsHtml = '';
        for (const fmt in formats) {
            formats[fmt].forEach(path => {
                let width = '?';
                const match = path.match(/\/(\d+)\./);
                if (match) width = match[1];
                optionsHtml += `<option value="/media/${path}">${fmt.toUpperCase()} ${width}px</option>`;
            });
        }

        inspector.innerHTML = `
            ${data.mime.startsWith('image/')
                ? `<picture>
                        ${formats.webp ? `<source type="image/webp" srcset="/media/${formats.webp[0]}">` : ''}
                        <img src="/media/${formats.jpg[0]}" alt="${data.alt}">
                   </picture>`
                : `<div>${data.name}</div>`}

            <strong>${data.name}</strong>
            <small>${data.size} · ${data.time}</small>

            <form method="post" enctype="multipart/form-data" action="<?= url('admin/media-save') ?>">
                <input type="hidden" name="replace_id" value="${data.id}">

                <label>
                    Alt text:
                    <input type="text" name="alt_text" value="${data.alt}">
                </label>

                <label>
                    Description:
                    <textarea name="description">${data.description}</textarea>
                </label>

                <label>
                    Replace file:
                    <input type="file" name="file">
                </label>

                <button type="submit">Save Changes</button>
                
            </form>

            <label>
                Choose size/format:
                <div class="flex items-center gap-sm">
                    <select id="sizeSelect">${optionsHtml}</select>
                    <button id="copyBtn" class="btn btn-primary nowrap" data-url="${data.previewUrl}">Copy URL</button>
                </div>
            </label>

            <form method="post" action="<?= url('admin/media-remove') ?>" class="js-confirm-form"
                data-confirm-title="Delete media"
                data-confirm="Do you really want to delete ${data.name}?"
                style="margin-top:.5rem;">
                <input type="hidden" name="id" value="${data.id}">
                <button class="btn btn-delete">Delete</button>
            </form>
        `;

        const copyBtn = document.getElementById('copyBtn');
        const select = document.getElementById('sizeSelect');
        
        copyBtn.onclick = () => {
            const url = select.value;

            copyText(url)
                .then(() => {
                    const original = copyBtn.textContent;

                    copyBtn.textContent = 'Copied ✓';
                    copyBtn.disabled = true;

                    setTimeout(() => {
                        copyBtn.textContent = original;
                        copyBtn.disabled = false;
                    }, 1500);
                });
        };

        function copyText(text) {
            if (navigator.clipboard?.writeText) {
                return navigator.clipboard.writeText(text);
            }

            // fallback for older browsers / http
            return new Promise(resolve => {
                const input = document.createElement('input');
                input.value = text;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                resolve();
            });
        }



    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/partials/layout.php';
?>

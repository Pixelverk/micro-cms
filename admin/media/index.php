<?php
// admin/media.php

$pageTitle = admin_trans('media_title');
$username  = current_username();

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

    // Determine preview URL for grid
    $previewUrl = null;
    if (!empty($formats['webp'])) {
        $previewUrl = '/media/' . $formats['webp'][0];
    } elseif (!empty($formats)) {
        $firstFormat = reset($formats);
        if (!empty($firstFormat)) $previewUrl = '/media/' . $firstFormat[0];
    }

    // Dimensions of each stored variant, so the picker can show what you are
    // about to copy. The paths are relative to the media directory; `sizes`
    // holds the resized ladder keyed by width.
    $variantSizes = [];
    foreach ($formats as $format => $paths) {
        foreach ((array) $paths as $path) {
            $file = STORAGE_PATH . '/media/' . $path;
            $dimensions = is_file($file) ? @getimagesize($file) : false;

            $variantSizes[$format][$path] = $dimensions
                ? ['width' => $dimensions[0], 'height' => $dimensions[1]]
                : null;
        }
    }

    $mediaFiles[] = [
        'id'            => $row['id'],
        'base_path'     => $row['base_path'],
        'preview_url'   => $previewUrl,
        'original_name' => $row['original_name'],
        'mime'          => $row['mime_type'],
        'size'          => round((int)$row['original_size'] / 1024, 1) . ' KB',
        'width'         => (int) ($row['width'] ?? 0),
        'height'        => (int) ($row['height'] ?? 0),
        'alt'           => $row['alt_text'] ?? '',
        'description'   => $row['description'] ?? '',
        'created_at'    => date('Y-m-d H:i', (int)$row['created_at']),
        'formats'       => $formats,
        'variant_sizes' => $variantSizes,
        'sizes'         => $sizes,
        'is_image'      => str_starts_with($row['mime_type'], 'image/'),
    ];
}

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('media_title')) ?></h2>
        <p><?= e(admin_trans('media_intro')) ?></p>
    </div>

    <div class="page-actions page-actions-inline">
        <!-- Search -->
        <form method="get" class="media-search">
            <label class="off-screen" for="media-search"><?= e(admin_trans('media_search_files')) ?></label>
            <input type="search" id="media-search" name="q" value="<?= e($search) ?>" placeholder="<?= e(admin_trans('media_search_files')) ?>">
        </form>

        <!-- Upload -->
        <form action="<?= url('admin/media/save') ?>" method="post" enctype="multipart/form-data" class="media-upload">
            <?= csrf_field() ?>
            <input type="file" name="file" required aria-label="<?= e(admin_trans('media_select_file')) ?>">
            <button type="submit" class="btn-primary"><?= e(admin_trans('media_upload')) ?></button>
        </form>
    </div>
</div>

<?php if (!$mediaFiles): ?>
    <div class="empty-state">
        <span class="empty-state-icon" aria-hidden="true"><?= icon('media-image', 24) ?></span>
        <p class="empty-state-title"><?= e(admin_trans('media_empty')) ?></p>
        <p><?= e(admin_trans('media_empty_help')) ?></p>
    </div>
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
                data-variant-sizes='<?= json_encode($file['variant_sizes'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                data-width="<?= (int) $file['width'] ?>"
                data-height="<?= (int) $file['height'] ?>"
                data-sizes='<?= json_encode($file['sizes'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                data-is-image='<?= $file['is_image'] ? '1' : '0' ?>'
            >
                <?php if ($file['is_image'] && $file['preview_url']): ?>
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
        <p><?= e(admin_trans('media_select_file')) ?></p>
    </div>

</div>

<?php endif; ?>


<script>
const inspector = document.getElementById('inspector');
const items = document.querySelectorAll('.media-item');

items.forEach(item => {
    item.addEventListener('click', () => {

        items.forEach(i => i.classList.remove('selected'));
        item.classList.add('selected');

        const data = item.dataset;
        const formats = JSON.parse(data.formats);
        const variantSizes = JSON.parse(data.variantSizes || '{}');
        const sizes   = JSON.parse(data.sizes || '{}');
        const isImage = data.isImage === '1';

        // Build the size dropdown from the stored variants
        let optionsHtml = '';
        let firstUrl = '';

        for (const fmt in formats) {
            formats[fmt].forEach(path => {
                const dim = (variantSizes[fmt] || {})[path];
                // The base file has no width in its name; the stored record has it.
                const size = dim || (isImage && !/-\d+\./.test(path) ? { width: data.width, height: data.height } : null);
                const detail = size && size.width ? `${size.width}\u00d7${size.height}` : '';
                const label = fmt.toUpperCase() + (detail ? ' ' + detail : '');

                if (!firstUrl) firstUrl = '/media/' + path;
                optionsHtml += `<option value="/media/${path}">${label}</option>`;
            });
        }

        // Fallback for non-images without formats
        if (!firstUrl && !isImage) {
            firstUrl = data.previewUrl || '/media/' + data.name;
            optionsHtml = `<option value="${firstUrl}">${data.name}</option>`;
        }

        inspector.innerHTML = `
            ${isImage
                ? `<picture class="media-preview">
                        ${formats.webp ? `<source type="image/webp" srcset="/media/${formats.webp[0]}">` : ''}
                        <img src="/media/${formats.jpg ? formats.jpg[0] : firstUrl}" alt="${data.alt}">
                   </picture>`
                : `<div>${data.name}</div>`}

            <div class="media-inspector-head">
                <strong>${data.name}</strong>
                <small>${data.size} · ${data.time}</small>
            </div>

            <form method="post" enctype="multipart/form-data" action="<?= url('admin/media/save') ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="replace_id" value="${data.id}">

                <div class="field">
                    <label class="field-label" for="media-alt"><?= e(admin_trans('media_alt_label')) ?></label>
                    <input class="field-input" type="text" id="media-alt" name="alt_text" value="${data.alt}">
                </div>

                <div class="field">
                    <label class="field-label" for="media-description"><?= e(admin_trans('media_description_label')) ?></label>
                    <textarea class="field-input" id="media-description" name="description" rows="3">${data.description}</textarea>
                </div>

                <div class="field">
                    <label class="field-label" for="media-replace"><?= e(admin_trans('media_replace_label')) ?></label>
                    <input type="file" id="media-replace" name="file">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary"><?= e(admin_trans('common_save')) ?></button>
                </div>
            </form>

            <div class="field">
                <label class="field-label" for="sizeSelect"><?= e(admin_trans('media_size_label')) ?></label>
                <div class="media-url-row">
                    <select class="field-input" id="sizeSelect">${optionsHtml}</select>
                    <button id="copyBtn" class="btn-secondary nowrap" data-url="${firstUrl}"><?= e(admin_trans('media_copy_url')) ?></button>
                </div>
            </div>

            <form method="post" action="<?= url('admin/media/remove') ?>" class="js-confirm-form media-delete"
                data-confirm-title="<?= e(admin_trans('media_delete')) ?>"
                data-confirm="<?= e(admin_trans('media_delete_confirm', ['name' => '${data.name}'])) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="${data.id}">
                <button class="btn-danger"><?= e(admin_trans('common_delete')) ?></button>
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

ob_start();
?>
<h3><?= e(admin_trans('media_title')) ?></h3>
<p><?= e(admin_trans('media_help')) ?></p>
<ul>
    <li><?= e(admin_trans('media_help_alt')) ?></li>
    <li><?= e(admin_trans('media_help_delete')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'editor', 'section' => 'media'];

include CMS_PATH . '/admin/partials/layout.php';
?>

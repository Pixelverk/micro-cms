<?php
// admin/media/index.php

$pageTitle = admin_trans('media_title');

$pdo = db();

// ----------------------------
// Filters
// ----------------------------
$search = trim($_GET['q'] ?? '');

// File-type tabs, counted over every file so each tab keeps its own total
// while a search or a type filter is active.
$typeCounts = [];

foreach ($pdo->query("SELECT original_name FROM media") as $nameRow) {
    $extension = strtolower(pathinfo((string) $nameRow['original_name'], PATHINFO_EXTENSION));

    $typeCounts[$extension] = ($typeCounts[$extension] ?? 0) + 1;
}

ksort($typeCounts);

$totalCount = array_sum($typeCounts);

$type = strtolower(trim((string) ($_GET['type'] ?? '')));

if ($type !== '' && !isset($typeCounts[$type])) {
    $type = '';
}

// URL that preserves the current filters while changing one of them.
$filterUrl = function (array $overrides = []) use ($type, $search): string {
    $query = array_filter(array_merge([
        'type' => $type,
        'q'    => $search,
    ], $overrides), static fn($value) => $value !== '' && $value !== null);

    return url('admin/media') . ($query ? '?' . http_build_query($query) : '');
};

// ----------------------------
// Load files
// ----------------------------
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

if ($type !== '') {
    $rows = array_filter($rows, static fn(array $row): bool => strtolower(pathinfo((string) $row['original_name'], PATHINFO_EXTENSION)) === $type);
}

$mediaFiles = [];

foreach ($rows as $row) {
    $formats = json_decode($row['formats_json'], true) ?? [];
    $isImage = str_starts_with((string) $row['mime_type'], 'image/');

    // The webp variant is the lightest to load; otherwise the first format.
    // Kept relative to the media directory, like the entries in formats_json.
    $previewPath = null;

    if (!empty($formats['webp'])) {
        $previewPath = $formats['webp'][0];
    } elseif (!empty($formats)) {
        $firstFormat = reset($formats);
        if (!empty($firstFormat)) {
            $previewPath = $firstFormat[0];
        }
    }

    // Dimensions of each stored variant, so the picker can show what you are
    // about to copy. The paths are relative to the media directory.
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
        'id'            => (int) $row['id'],
        'base_path'     => (string) $row['base_path'],
        'preview_path'  => $previewPath,
        'original_name' => (string) $row['original_name'],
        'mime'          => (string) $row['mime_type'],
        'size'          => round((int) $row['original_size'] / 1024, 1) . ' KB',
        'width'         => (int) ($row['width'] ?? 0),
        'height'        => (int) ($row['height'] ?? 0),
        'alt'           => (string) ($row['alt_text'] ?? ''),
        'description'   => (string) ($row['description'] ?? ''),
        'created_at'    => format_local_datetime((int) $row['created_at'], 'Y-m-d H:i'),
        'formats'       => $formats,
        'variant_sizes' => $variantSizes,
        'is_image'      => $isImage,
        'extension'     => strtoupper(pathinfo((string) $row['original_name'], PATHINFO_EXTENSION)),
    ];
}

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('media_title')) ?></h2>
        <p><?= e(admin_trans('media_intro')) ?></p>
    </div>

    <div class="page-actions">
        <?php /* The file input posts the form it is linked to by id, so the button
                 sits straight in the header like the page actions elsewhere. */ ?>
        <form id="media-upload" action="<?= url('admin/media/save') ?>" method="post"
              enctype="multipart/form-data" hidden>
            <?= csrf_field() ?>
        </form>

        <label class="btn-primary media-upload">
            <?= icon('plus', 16) ?><?= e(admin_trans('media_upload')) ?>
            <input type="file" name="file" form="media-upload" class="visually-hidden" required
                   onchange="this.form.submit()">
        </label>
    </div>
</div>

<div class="content-filters">
    <div class="status-tabs">
        <a href="<?= e($filterUrl(['type' => ''])) ?>"
           class="status-tab <?= $type === '' ? 'active' : '' ?>">
            <?= e(admin_trans('media_all_files')) ?>
            <span class="status-tab-count"><?= (int) $totalCount ?></span>
        </a>
        <?php foreach ($typeCounts as $extension => $count): ?>
            <?php if ($extension === '') continue; ?>
            <a href="<?= e($filterUrl(['type' => $extension])) ?>"
               class="status-tab <?= $type === $extension ? 'active' : '' ?>">
                <?= e(strtoupper($extension)) ?>
                <span class="status-tab-count"><?= (int) $count ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="content-search">
        <?php if ($type !== ''): ?>
            <input type="hidden" name="type" value="<?= e($type) ?>">
        <?php endif; ?>
        <label class="off-screen" for="media-search"><?= e(admin_trans('media_search_files')) ?></label>
        <input type="search" id="media-search" name="q" value="<?= e($search) ?>"
               placeholder="<?= e(admin_trans('media_search_files')) ?>">
        <?php if ($search !== ''): ?>
            <a href="<?= e($filterUrl(['q' => ''])) ?>" class="btn-small btn-muted"><?= e(admin_trans('common_clear')) ?></a>
        <?php endif; ?>
    </form>
</div>

<?php if (!$mediaFiles): ?>
    <div class="empty-state">
        <span class="empty-state-icon" aria-hidden="true"><?= icon('media-image', 24) ?></span>
        <p class="empty-state-title">
            <?= $type !== '' || $search !== ''
                ? e(admin_trans('media_empty_filtered'))
                : e(admin_trans('media_empty')) ?>
        </p>
        <?php if ($type === '' && $search === ''): ?>
            <p><?= e(admin_trans('media_empty_help')) ?></p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th class="media-thumb"><?= e(admin_trans('media_preview')) ?></th>
                <th><?= e(admin_trans('common_name')) ?></th>
                <th><?= e(admin_trans('common_type')) ?></th>
                <th><?= e(admin_trans('media_dimensions')) ?></th>
                <th><?= e(admin_trans('common_size')) ?></th>
                <th><?= e(admin_trans('common_created')) ?></th>
                <th class="col-actions-icons"><?= e(admin_trans('common_actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($mediaFiles as $file): ?>
            <?php /* The row carries its own record: both the name and the pencil
                       open the same dialog, and the payload is written once. */ ?>
            <tr data-media="<?= e(json_encode($file, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
                <td class="media-thumb">
                    <?php if ($file['is_image'] && $file['preview_path']): ?>
                        <picture>
                            <?php if (!empty($file['formats']['webp'])): ?>
                                <source type="image/webp" srcset="<?= e(url('media/' . $file['formats']['webp'][0])) ?>">
                            <?php endif; ?>
                            <img src="<?= e(url('media/' . ($file['formats']['jpg'][0] ?? $file['preview_path']))) ?>"
                                 loading="lazy" alt="">
                        </picture>
                    <?php else: ?>
                        <span class="media-thumb-ext"><?= e($file['extension'] !== '' ? $file['extension'] : 'FILE') ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button" class="btn-text js-media-view">
                        <span><?= e($file['original_name']) ?></span>
                    </button>
                </td>
                <td><?= e($file['mime']) ?></td>
                <td>
                    <?= $file['width'] > 0 && $file['height'] > 0
                        ? e($file['width'] . '×' . $file['height'])
                        : '—' ?>
                </td>
                <td><?= e($file['size']) ?></td>
                <td><?= e($file['created_at']) ?></td>
                <?php /* The buttons live in their own box: a flex cell would drop
                           out of the table's layout and its borders would sit
                           away from the row's. */ ?>
                <td class="col-actions-icons">
                    <div class="actions media-actions">
                        <button type="button" class="btn-text btn-icon js-media-view"
                                title="<?= e(admin_trans('common_details')) ?>"
                                aria-label="<?= e(admin_trans('common_details')) ?>">
                            <?= icon('edit', 16) ?>
                        </button>

                        <form method="post" action="<?= url('admin/media/remove') ?>"
                              class="inline-form js-confirm-form"
                              data-confirm-title="<?= e(admin_trans('media_delete')) ?>"
                              data-confirm="<?= e(admin_trans('media_delete_confirm', ['name' => $file['original_name']])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
                            <button type="submit" class="btn-delete btn-small btn-icon"
                                    title="<?= e(admin_trans('common_delete')) ?>"
                                    aria-label="<?= e(admin_trans('common_delete')) ?>">
                                <?= icon('trash', 16) ?>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php /* One dialog, filled by whichever row's Details button was pressed. A
         modal per row would be simpler to read but would repeat this markup for
         every file in the library. The variant links are made absolute, so a
         copied one works wherever it is pasted. */ ?>
<div id="media-view" class="modal-backdrop" data-media-base="<?= e(seo_absolute_url(url('media/'))) ?>" hidden>
    <div class="modal modal-lg media-modal">
        <div class="modal-header">
            <h3>
                <span id="media-view-name"></span>
                <span class="text-muted" id="media-view-meta"></span>
            </h3>
            <button type="button" class="close-modal js-media-close"
                    aria-label="<?= e(admin_trans('common_close')) ?>">&times;</button>
        </div>

        <div class="modal-body">
            <div id="media-view-preview"></div>

            <form method="post" enctype="multipart/form-data" action="<?= url('admin/media/save') ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="replace_id" id="media-view-id" value="">

                <div class="field">
                    <label class="field-label" for="media-view-alt"><?= e(admin_trans('media_alt_label')) ?></label>
                    <input class="field-input" type="text" id="media-view-alt" name="alt_text" value="">
                </div>

                <div class="field">
                    <label class="field-label" for="media-view-description"><?= e(admin_trans('media_description_label')) ?></label>
                    <textarea class="field-input" id="media-view-description" name="description" rows="3"></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary"><?= e(admin_trans('common_save')) ?></button>
                </div>
            </form>

            <div class="field">
                <label class="field-label" for="media-view-size"><?= e(admin_trans('media_variants_label')) ?></label>
                <div class="media-url-row">
                    <select class="field-input" id="media-view-size"></select>
                    <button type="button" class="btn-secondary" id="media-view-copy"><?= e(admin_trans('media_copy_url')) ?></button>
                </div>
            </div>

            <form method="post" action="<?= url('admin/media/remove') ?>"
                  class="media-delete js-confirm-form" id="media-view-delete"
                  data-confirm-title="<?= e(admin_trans('media_delete')) ?>"
                  data-confirm-template="<?= e(admin_trans('media_delete_confirm', ['name' => '__name__'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="media-view-delete-id" value="">
                <button type="submit" class="btn-text media-delete-link"><?= e(admin_trans('media_delete')) ?></button>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    const backdrop = document.getElementById('media-view');
    const copyButton = document.getElementById('media-view-copy');
    const deleteForm = document.getElementById('media-view-delete');
    const sizeSelect = document.getElementById('media-view-size');
    const mediaBase = backdrop.dataset.mediaBase || '/media/';

    /* The dialog is filled with innerHTML, so every value that came from the
       database is escaped first. */
    const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
    ));

    const close = () => {
        backdrop.hidden = true;
        backdrop.style.display = '';
    };

    const copyText = text => navigator.clipboard?.writeText
        ? navigator.clipboard.writeText(text)
        : new Promise(resolve => {
            const input = document.createElement('input');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            resolve();
        });

    document.querySelectorAll('.js-media-view').forEach(button => {
        button.addEventListener('click', () => {
            const media = JSON.parse(button.closest('tr').dataset.media);
            const formats = media.formats || {};

            document.getElementById('media-view-name').textContent = media.original_name;
            document.getElementById('media-view-meta').textContent = [
                media.mime,
                media.width > 0 && media.height > 0 ? media.width + '\u00d7' + media.height : '',
                media.size,
                media.created_at,
            ].filter(Boolean).join(' \u00b7 ');

            document.getElementById('media-view-id').value = media.id;
            document.getElementById('media-view-alt').value = media.alt;
            document.getElementById('media-view-description').value = media.description;
            document.getElementById('media-view-delete-id').value = media.id;

            deleteForm.dataset.confirm = (deleteForm.dataset.confirmTemplate || '')
                .replace('__name__', media.original_name);

            document.getElementById('media-view-preview').innerHTML =
                media.is_image && media.preview_path
                    ? `<picture class="media-preview">
                           ${formats.webp ? `<source type="image/webp" srcset="/media/${esc(formats.webp[0])}">` : ''}
                           <img src="/media/${esc(formats.jpg ? formats.jpg[0] : media.preview_path)}" alt="${esc(media.alt)}">
                       </picture>`
                    : '';

            // One option per stored variant, so the link that gets copied is the
            // exact file wanted.
            let options = '';

            for (const format in formats) {
                formats[format].forEach(path => {
                    const dimensions = (media.variant_sizes[format] || {})[path];
                    const detail = dimensions ? ' ' + dimensions.width + '\u00d7' + dimensions.height : '';

                    options += `<option value="${esc(mediaBase + path)}">${esc(format.toUpperCase() + detail)}</option>`;
                });
            }

            // Files without generated variants still have their original.
            if (options === '') {
                options = `<option value="${esc(mediaBase + (media.preview_path || media.base_path))}">${esc(media.original_name)}</option>`;
            }

            sizeSelect.innerHTML = options;

            backdrop.hidden = false;
            backdrop.style.display = 'flex';
            document.getElementById('media-view-alt').focus();
        });
    });

    backdrop.addEventListener('click', event => {
        if (event.target === backdrop) close();
    });

    backdrop.querySelectorAll('.js-media-close').forEach(button => {
        button.addEventListener('click', close);
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !backdrop.hidden) close();
    });

    copyButton.addEventListener('click', () => {
        copyText(sizeSelect.value).then(() => {
            const original = copyButton.textContent;

            copyButton.textContent = '<?= e(admin_trans('common_copied')) ?>';
            copyButton.disabled = true;

            setTimeout(() => {
                copyButton.textContent = original;
                copyButton.disabled = false;
            }, 1500);
        });
    });
})();
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

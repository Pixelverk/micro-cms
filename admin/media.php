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
    $sql .= " WHERE filename LIKE :q OR original_name LIKE :q OR alt_text LIKE :q OR description LIKE :q";
    $params['q'] = "%{$search}%";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mediaFiles = [];

foreach ($rows as $row) {
    $mediaFiles[] = [
        'id'   => $row['id'],
        'path' => $row['path'],
        'url'  => url('media/' . $row['path']),
        'name' => $row['filename'],
        'size' => round($row['size'] / 1024, 1) . ' KB',
        'mime' => $row['mime_type'],
        'alt'  => $row['alt_text'] ?? '',
        'description' => $row['description'] ?? '',
        'time' => date('Y-m-d H:i', (int)$row['created_at']),
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
                data-url="<?= e($file['url']) ?>"
                data-name="<?= e($file['name']) ?>"
                data-alt="<?= e($file['alt']) ?>"
                data-description="<?= e($file['description']) ?>"
                data-mime="<?= e($file['mime']) ?>"
                data-size="<?= e($file['size']) ?>"
                data-time="<?= e($file['time']) ?>"
            >
                <?php if (str_starts_with($file['mime'], 'image/')): ?>
                    <img src="<?= e($file['url']) ?>" loading="lazy">
                <?php else: ?>
                    <div class="media-file"><?= e($file['name']) ?></div>
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
.media-item { cursor:pointer; border:2px solid transparent; }
.media-item.selected { border-color:#4f46e5; }
.media-item img,
.media-file { width:100%; height:120px; object-fit:cover; border-radius:6px; background:#f2f2f2; display:flex; align-items:center; justify-content:center; }
.media-inspector { width:320px; border-left:1px solid #ddd; padding-left:1rem; }
.media-inspector img { width:100%; margin-bottom:1rem; }
.media-inspector label { display:block; margin-bottom:.75rem; }
.media-inspector input,
.media-inspector textarea { width:100%; padding:.25rem; margin-top:.25rem; }
</style>


<script>
const inspector = document.getElementById('inspector');
const items = document.querySelectorAll('.media-item');

items.forEach(item => {
    item.addEventListener('click', () => {

        // Highlight selected
        items.forEach(i => i.classList.remove('selected'));
        item.classList.add('selected');

        const data = item.dataset;

        inspector.innerHTML = `
            ${data.mime.startsWith('image/')
                ? `<img src="${data.url}">`
                : `<div>${data.name}</div>`}

            <strong>${data.name}</strong>
            <small>${data.size} · ${data.time}</small>

            <form method="post" action="<?= url('admin/media-save') ?>">
                <input type="hidden" name="replace_id" value="${data.id}">
                <label>
                    Alt text:
                    <input type="text" name="alt_text" value="${data.alt}">
                </label>
                <label>
                    Description:
                    <textarea name="description">${data.description}</textarea>
                </label>
                <button type="submit">Save Metadata</button>
            </form>

            <button id="copyBtn">Copy URL</button>

            <form method="post" action="<?= url('admin/media-remove') ?>" style="margin-top:.5rem;">
                <input type="hidden" name="id" value="${data.id}">
                <button class="btn-delete">Delete</button>
            </form>

            <form method="post" enctype="multipart/form-data" action="<?= url('admin/media-save') ?>" style="margin-top:.5rem;">
                <input type="hidden" name="replace_id" value="${data.id}">
                <input type="file" name="file" required>
                <button>Replace File</button>
            </form>
        `;

        document.getElementById('copyBtn').onclick = () => {
            navigator.clipboard.writeText(data.url)
                .then(() => alert('URL copied!'))
                .catch(() => alert('Failed to copy.'));
        };
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/partials/layout.php';
?>
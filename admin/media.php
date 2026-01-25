<?php
// admin/media.php

$pageTitle = 'Media Manager';
$username  = $_SESSION['user_id'] ?? 'User';

// ----------------------------
// Load media files
// ----------------------------
$mediaRoot = STORAGE_PATH . '/media';
$mediaFiles = [];

if (is_dir($mediaRoot)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mediaRoot, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($rii as $file) {
        if (!$file->isFile()) continue;

        $relativePath = str_replace($mediaRoot . '/', '', $file->getPathname());

        $mediaFiles[] = [
            'path' => $relativePath,
            'url'  => url('media/' . $relativePath),
            'name' => $file->getFilename(),
            'size' => round($file->getSize() / 1024, 1) . ' KB',
            'time' => date('Y-m-d', $file->getMTime()),
            'type' => mime_content_type($file->getPathname()),
        ];
    }
}

// Sort newest first
usort($mediaFiles, fn ($a, $b) => strcmp($b['time'], $a['time']));

// ----------------------------
// Render page
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p>Manage uploaded media files.</p>
    </div>
    <div class="page-actions">
        <form action="<?= url('admin/media-save') ?>" method="post" enctype="multipart/form-data">
            <input type="file" name="file" required>
            <button type="submit">Upload</button>
        </form>
    </div>
</div>

<?php if (!$mediaFiles): ?>
    <p>No media uploaded yet.</p>
<?php else: ?>
    <div class="media-grid">
        <?php foreach ($mediaFiles as $file): ?>
            <div class="media-item">
                <?php if (str_starts_with($file['type'], 'image/')): ?>
                    <img src="<?= e($file['url']) ?>" alt="<?= e($file['name']) ?>">
                <?php else: ?>
                    <div class="media-file">
                        <?= e($file['name']) ?>
                    </div>
                <?php endif; ?>

                <div class="media-meta">
                    <strong><?= e($file['name']) ?></strong>
                    <small><?= e($file['size']) ?> · <?= e($file['time']) ?></small>
                </div>

                <div class="media-actions">
                    <!-- Copy URL button -->
                    <button type="button" class="copy-url-btn" data-url="<?= e($file['url']) ?>">
                        Copy URL
                    </button>

                    <!-- Delete form -->                   
                    <form action="<?= url('admin/media-remove') ?>" method="post" class="js-confirm-form" data-confirm-title="Delete media" data-confirm="Do you want to remove <?= e($file['name']) ?>">
                        <input type="hidden" name="path" value="<?= e($file['path']) ?>">
                        <button type="submit" class="btn-delete">Delete</button>
                    </form>

                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
.media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1.5rem;
}

.media-item {
    background: #fff;
    border: 1px solid #ddd;
    padding: 0.75rem;
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.media-item img {
    width: 100%;
    height: 140px;
    object-fit: cover;
    border-radius: 4px;
}

.media-file {
    height: 140px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f3f3f3;
    font-size: 0.9rem;
}

.media-meta {
    font-size: 0.8rem;
}

.media-actions {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
}

.media-actions button {
    font-size: 0.8rem;
    cursor: pointer;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const buttons = document.querySelectorAll('.copy-url-btn');

    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            const url = btn.dataset.url;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                // Modern async API
                navigator.clipboard.writeText(url)
                    .then(() => {
                        btn.textContent = 'Copied!';
                        setTimeout(() => btn.textContent = 'Copy URL', 1500);
                    })
                    .catch(err => {
                        fallbackCopy(url, btn);
                    });
            } else {
                // Fallback for older browsers or non-secure context
                fallbackCopy(url, btn);
            }
        });
    });

    function fallbackCopy(text, btn) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        try {
            const success = document.execCommand('copy');
            if (success) {
                btn.textContent = 'Copied!';
                setTimeout(() => btn.textContent = 'Copy URL', 1500);
            } else {
                alert('Failed to copy to clipboard.');
            }
        } catch (err) {
            alert('Failed to copy to clipboard: ' + err);
        } finally {
            document.body.removeChild(textarea);
        }
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/partials/layout.php';
?>

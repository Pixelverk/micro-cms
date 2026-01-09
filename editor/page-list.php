<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/pages.php';

require_login();

$pageTitle = 'Pages';
$username = $_SESSION['user_id'] ?? 'User';
$pages = list_pages();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= htmlspecialchars($username) ?> 👋</h2>
        <p>Manage your pages below.</p>
    </div>
    <div class="page-actions">
        <a href="page-add.php" class="btn-primary">+ Add New Page</a>
    </div>
</div>

<?php if (empty($pages)): ?>
    <p>No pages found. Create your first page.</p>
<?php else: ?>
    <table class="pages-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Slug</th>
                <th style="width: 180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pages as $page): ?>
                <tr>
                    <td><?= htmlspecialchars($page['title']) ?></td>
                    <td>
                        <code><?= htmlspecialchars($page['slug']) ?></code>
                    </td>
                    <td class="actions">
                        <a
                            href="page-edit.php?slug=<?= urlencode($page['slug']) ?>"
                            class="btn-small"
                        >
                            Edit
                        </a>

                        <button
                            type="button"
                            class="btn-delete btn-small delete-page-btn"
                            data-slug="<?= htmlspecialchars($page['slug']) ?>"
                        >
                            Delete
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
document.querySelectorAll('.delete-page-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const slug = btn.dataset.slug;
        if (
            confirm(`Are you sure you want to delete the page "${slug}"?\n\nThis action cannot be undone.`)
        ) {
            window.location.href = `page-remove.php?slug=${encodeURIComponent(slug)}`;
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/_partials/layout.php';
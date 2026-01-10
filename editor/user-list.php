<?php
require_once __DIR__ . '/_core/bootstrap.php';
require_once __DIR__ . '/_core/auth.php';

require_login();

$pageTitle = 'Users';
$username = $_SESSION['user_id'] ?? 'User';
$users = load_users();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p>Manage users below.</p>
    </div>
    <div class="page-actions">
        <a href="<?= url('editor/user-add.php') ?>" class="btn-primary">+ Add New User</a>
    </div>
</div>

<?php if (empty($users)): ?>
    <p>No users found.</p>
<?php else: ?>
    <table class="pages-table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Created</th>
                <th style="width: 180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $name => $data): ?>
            <tr>
                <td><?= e($name) ?></td>
                <td>
                    <?= isset($data['created'])
                        ? date('Y-m-d H:i', (int)$data['created'])
                        : '—' ?>
                </td>
                <td class="actions">
                    <a href="<?= url('editor/user-edit.php?username=' . urlencode($name)) ?>" class="btn-small">Edit</a>

                    <?php if ($name !== $username): ?>
                        <button
                            type="button"
                            class="btn-small btn-delete delete-user-btn"
                            data-username="<?= e($name) ?>">
                            Delete
                        </button>
                    <?php else: ?>
                        <button
                            type="button"
                            class="btn-small btn-delete delete-user-btn"
                            disabled>
                            Nope
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
document.querySelectorAll('.delete-user-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const username = btn.dataset.username;
        if (!username) return;

        if (confirm(`Are you sure you want to delete user "${username}"?\nThis cannot be undone.`)) {
            window.location.href = "<?= url('editor/user-remove.php') ?>?username=" + encodeURIComponent(username);
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/_partials/layout.php';
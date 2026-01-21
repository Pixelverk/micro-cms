<?php

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
        <a href="<?= url('admin/user-add') ?>" class="btn-primary">+ Add New User</a>
    </div>
</div>

<?php if (empty($users)): ?>
    <p>No users found.</p>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Created</th>
                <th>Last Login</th>
                <th style="width: 180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $name => $data): ?>
            <tr>
                <td><?= e($name) ?></td>
                <td>
                    <?= isset($data['created_at'])
                        ? date('Y-m-d H:i', (int)$data['created_at'])
                        : '—' ?>
                </td>
                <td>
                    <?= isset($data['last_login'])
                        ? date('Y-m-d H:i', (int)$data['last_login'])
                        : '—' ?>
                </td>
                <td class="actions">
                    <a href="<?= url('admin/user-edit') . '?username=' . urlencode($name) ?>" class="btn-small">Edit</a>

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
            window.location.href = "<?= url('admin/user-remove') ?>?username=" + encodeURIComponent(username);
        }
    });
});
</script>

<?php
$content = ob_get_clean();

// page help
ob_start();
?>
<h3>User list</h3>
<p>This screen lists all CMS users.</p>
<p>Use the buttons to edit or delete users, or add a new user.</p>
<p>You cannot delete your own account while logged in.</p>
<?php
$pageHelp = ob_get_clean();

include __DIR__ . '/partials/layout.php';
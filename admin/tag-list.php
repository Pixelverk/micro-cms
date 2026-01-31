<?php
// admin/tag-list.php

$pageTitle = 'Tags';
$username  = $_SESSION['user_id'] ?? 'User';

$pdo = db();

// ----------------------------
// Search
// ----------------------------
$search = trim($_GET['q'] ?? '');

// ----------------------------
// Load tags
// ----------------------------
$sql = "SELECT * FROM taxonomy WHERE type = 'tag'";
$params = [];

if ($search !== '') {
    $sql .= " AND name LIKE :q";
    $params['q'] = "%{$search}%";
}

$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Tags</h2>
    </div>

    <div class="page-actions flex gap-md items-center">

        <!-- Search -->
        <form method="get" style="margin-right:1rem;">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search tags…">
        </form>

        <!-- Add New -->
        <a href="<?= url('admin/tag-edit') ?>" class="btn-primary">
            + Add New Tag
        </a>

    </div>
</div>

<?php if (empty($tags)): ?>
    <p>No tags found.</p>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Slug</th>
                <th>Description</th>
                <th>Created</th>
                <th>Updated</th>
                <th style="width:180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tags as $tag): ?>
            <tr>
                <td><?= e($tag['name']) ?></td>
                <td><code><?= e($tag['slug']) ?></code></td>
                <td><?= e($tag['description']) ?></td>
                <td><?= format_local_datetime($tag['created_at'], 'Y-m-d') ?></td>
                <td><?= format_local_datetime($tag['updated_at'], 'Y-m-d') ?></td>
                <td class="actions">
                    <a href="<?= url('admin/tag-edit') ?>?id=<?= (int)$tag['id'] ?>" class="btn-small">Edit</a>

                    <form method="post" action="<?= url('admin/tag-delete') ?>" class="js-confirm" 
                        data-confirm-title="Delete tag"
                        data-confirm="Do you want to remove the tag: <?= e($tag['name']) ?>?"
                        style="display:inline-block; margin:0;">
                        <input type="hidden" name="id" value="<?= (int)$tag['id'] ?>">
                        <button type="submit" class="btn-delete btn-small">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
$content = ob_get_clean();

// ----------------------------
// Help panel
// ----------------------------
ob_start();
?>
<h3>Tags list</h3>
<p>This screen shows all tags you have created.</p>
<ul>
    <li><strong>Name</strong> is what will appear on the front-end.</li>
    <li><strong>Slug</strong> is the URL-friendly identifier.</li>
    <li><strong>Description</strong> is optional and can describe the tag.</li>
    <li><strong>Created</strong> and <strong>Updated</strong> reflect timestamps.</li>
</ul>
<?php
$pageHelp = ob_get_clean();

include __DIR__ . '/partials/layout.php';
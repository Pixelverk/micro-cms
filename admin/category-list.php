<?php
// admin/category-list.php

$pageTitle = 'Categories';
$username  = $_SESSION['user_id'] ?? 'User';

$pdo = db();

// ----------------------------
// Search
// ----------------------------
$search = trim($_GET['q'] ?? '');

// ----------------------------
// Load categories
// ----------------------------
$sql = "SELECT * FROM taxonomy WHERE taxonomy_type = 'category'";
$params = [];

if ($search !== '') {
    $sql .= " AND name LIKE :q";
    $params['q'] = "%{$search}%";
}

$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------
// Render
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Categories</h2>
    </div>

    <div class="page-actions flex gap-md items-center">

        <!-- Search -->
        <form method="get" style="margin-right:1rem;">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search categories…">
        </form>

        <!-- Add New -->
        <a href="<?= url('admin/category-edit') ?>" class="btn-primary">
            + Add New Category
        </a>

    </div>
</div>

<?php if (empty($categories)): ?>
    <p>No categories found.</p>
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
        <?php foreach ($categories as $cat): ?>
            <tr>
                <td><?= e($cat['name']) ?></td>
                <td><code><?= e($cat['slug']) ?></code></td>
                <td><?= e($cat['description']) ?></td>
                <td><?= format_local_datetime($cat['created_at'], 'Y-m-d') ?></td>
                <td><?= format_local_datetime($cat['updated_at'], 'Y-m-d') ?></td>
                <td class="actions">
                    <a href="<?= url('admin/category-edit') ?>?id=<?= (int)$cat['id'] ?>" class="btn-small">Edit</a>

                    <form method="post" action="<?= url('admin/category-remove') ?>" class="js-confirm-form" 
                        data-confirm-title="Delete category"
                        data-confirm="Do you want to remove the category: <?= e($cat['name']) ?>?"
                        style="display:inline-block; margin:0;">
                        <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
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
<h3>Category list</h3>
<p>This screen shows all categories you have created.</p>
<ul>
    <li><strong>Name</strong> is what will appear on the front-end.</li>
    <li><strong>Slug</strong> is the URL-friendly identifier.</li>
    <li><strong>Description</strong> is optional and can describe the category.</li>
    <li><strong>Created</strong> and <strong>Updated</strong> reflect timestamps.</li>
</ul>
<?php
$pageHelp = ob_get_clean();

include __DIR__ . '/partials/layout.php';

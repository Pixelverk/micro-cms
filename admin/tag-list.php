<?php
// admin/tag-list.php

$pageTitle = 'Tags';
$username  = $_SESSION['user_id'] ?? 'User';

$pdo = db();

// ----------------------------
// Theme / content types
// ----------------------------
$theme = theme_config();
$contentTypes = $theme['content_types'] ?? [];

// filter by content type (optional)
$type = $_GET['type'] ?? '';

// search
$search = trim($_GET['q'] ?? '');

// ----------------------------
// Build query
// ----------------------------
$sql = "
    SELECT *
    FROM taxonomy
    WHERE taxonomy_type = 'tag'
";

$params = [];

if ($type !== '') {
    $sql .= " AND content_type = :type";
    $params['type'] = $type;
}

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
        <p>Hello, <?= e($username) ?> 👋</p>
    </div>

    <div class="page-actions flex gap-md items-center">

        <!-- Content type filter -->
        <form method="get">
            <select name="type" onchange="this.form.submit()">
                <option value="">All types</option>
                <?php foreach ($contentTypes as $key => $config): ?>
                    <option value="<?= e($key) ?>"
                        <?= $type === $key ? 'selected' : '' ?>>
                        <?= e($config['label'] ?? ucfirst($key)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <!-- Search -->
        <form method="get">
            <?php if ($type): ?>
                <input type="hidden" name="type" value="<?= e($type) ?>">
            <?php endif; ?>
            <input
                type="text"
                name="q"
                value="<?= e($search) ?>"
                placeholder="Search tags…"
            >
        </form>

        <!-- Add -->
        <a href="<?= url('admin/tag-edit') ?>" class="btn-primary">
            + Add Tag
        </a>
    </div>
</div>

<?php if (!$tags): ?>
    <p>No tags found.</p>
<?php else: ?>

<table class="content-table">
    <thead>
        <tr>
            <th>Name</th>
            <th>Slug</th>
            <th>Content Type</th>
            <th>Updated</th>
            <th style="width:160px;">Actions</th>
        </tr>
    </thead>

    <tbody>
    <?php foreach ($tags as $tag): ?>
        <tr>
            <td><?= e($tag['name']) ?></td>

            <td>
                <code><?= e($tag['slug']) ?></code>
            </td>

            <td>
                <?= e($contentTypes[$tag['content_type']]['label']
                    ?? ucfirst($tag['content_type'])) ?>
            </td>

            <td>
                <?= format_local_datetime($tag['updated_at'], 'Y-m-d') ?>
            </td>

            <td class="actions">

                <a href="<?= url('admin/tag-edit') ?>?id=<?= (int)$tag['id'] ?>"
                   class="btn-small">
                    Edit
                </a>

                <form
                    action="<?= url('admin/tag-remove') ?>"
                    method="post"
                    class="js-confirm-form"
                    data-confirm-title="Delete tag"
                    data-confirm="Delete tag '<?= e($tag['name']) ?>'?"
                    style="display:inline"
                >
                    <input type="hidden" name="id" value="<?= (int)$tag['id'] ?>">
                    <button type="submit" class="btn-delete btn-small">
                        Delete
                    </button>
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
<h3>Tags</h3>
<p>Tags let you label content with multiple keywords.</p>
<ul>
    <li>Unlike categories, you can assign many tags to one item</li>
    <li>Useful for filtering, search, and grouping related content</li>
    <li>Tags are specific to a content type</li>
</ul>
<?php
$pageHelp = ob_get_clean();

include __DIR__ . '/partials/layout.php';
?>
<?php
declare(strict_types=1);

$pageTitle = admin_trans('forms_title');
$username  = current_username();

$pdo   = db();
$theme = theme_config();

$formTypes = $theme['form_types'] ?? [];

// ----------------------------
// Filter
// ----------------------------
$activeForm = $_GET['form'] ?? null;

$params = [];
$sql = "
    SELECT *
    FROM form_submissions
";

if ($activeForm && isset($formTypes[$activeForm])) {
    $sql .= " WHERE form_type = :form_type";
    $params['form_type'] = $activeForm;
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------
// Render page
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('common_hello', ['name' => $username])) ?></h2>
        <p><?= e(admin_trans('forms_intro')) ?></p>
    </div>
</div>

<div class="form-card">

    <!-- Filter -->
    <form method="get" style="margin-bottom:1rem;">
        <label>
            <strong><?= e(admin_trans('forms_type')) ?></strong>
            <select name="form" onchange="this.form.submit()">
                <option value=""><?= e(admin_trans('forms_all')) ?></option>
                <?php foreach ($formTypes as $key => $meta): ?>
                    <option value="<?= e($key) ?>" <?= $key === $activeForm ? 'selected' : '' ?>>
                        <?= e($meta['label'] ?? ucfirst($key)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <?php if (!$submissions): ?>
        <p><?= e(admin_trans('forms_empty')) ?></p>
    <?php else: ?>

        <table class="admin-table">
            <thead>
                <tr>
                    <th><?= e(admin_trans('forms_form')) ?></th>
                    <th><?= e(admin_trans('forms_submitted_at')) ?></th>
                    <th><?= e(admin_trans('forms_data')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $row): ?>
                    <?php
                    $data = json_decode($row['data'], true);
                    if (!is_array($data)) {
                        $data = [];
                    }
                    ?>
                    <tr>
                        <td>
                            <?= e($formTypes[$row['form_type']]['label']
                                ?? ucfirst($row['form_type'])) ?>
                        </td>

                        <td>
                            <?= date('Y-m-d H:i', (int)$row['created_at']) ?>
                        </td>

                        <td>
                            <details>
                                <summary><?= e(admin_trans('common_view')) ?></summary>
                                <ul style="margin-top:0.5rem;">
                                    <?php foreach ($data as $key => $value): ?>
                                        <li>
                                            <strong><?= e(ucfirst($key)) ?>:</strong>
                                            <?= nl2br(e((string)$value)) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('forms_title')) ?></h3>
<p><?= e(admin_trans('forms_help')) ?></p>
<p><?= e(admin_trans('forms_help_email')) ?></p>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'form-submissions'];

include CMS_PATH . '/admin/partials/layout.php';
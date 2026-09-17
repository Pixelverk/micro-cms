<?php

$pageTitle = 'Users';
$username = current_username();
$users = load_users();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p><?= e(admin_trans('dashboard_manage_users')) ?></p>
    </div>
    <div class="page-actions">
        <a href="<?= url('admin/user/add') ?>" class="btn-primary"><?= e(admin_trans('user_add')) ?></a>
    </div>
</div>

<?php if (empty($users)): ?>
    <p><?= e(admin_trans('user_empty')) ?></p>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th><?= e(admin_trans('user_username')) ?></th>
                <th><?= e(admin_trans('common_created')) ?></th>
                <th><?= e(admin_trans('user_last_login')) ?></th>
                <th style="width: 180px;"><?= e(admin_trans('common_actions')) ?></th>
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
                    <a href="<?= url('admin/user/edit') . '?username=' . urlencode($name) ?>" class="btn-small"><?= e(admin_trans('common_edit')) ?></a>

                    <?php if ($name !== $username): ?>
                        <form method="post"
                            action="<?= url('admin/user/remove') ?>"
                            data-confirm="<?= e(admin_trans('user_delete_confirm', ['name' => $name])) ?>"
                            data-confirm-title="<?= e(admin_trans('user_delete')) ?>"
                            class="inline-form js-confirm-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="username" value="<?= e($name) ?>">
                            <button type="submit" class="btn-delete btn-small">
                                <?= e(admin_trans('common_delete')) ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <button
                            type="button"
                            class="btn-small btn-delete delete-user-btn"
                            disabled>
                            <?= e(admin_trans('common_no')) ?>
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; 

$content = ob_get_clean();

// page help
ob_start();
?>
<h3><?= e(admin_trans('nav_users')) ?></h3>
<p><?= e(admin_trans('user_list_help')) ?></p>
<p><?= e(admin_trans('user_actions_help')) ?></p>
<p><?= e(admin_trans('user_own_account_help')) ?></p>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'roles'];

include CMS_PATH . '/admin/partials/layout.php';
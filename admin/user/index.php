<?php

$pageTitle = admin_trans('nav_users');
$username = current_username();
$users = load_users();

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('nav_users')) ?></h2>
        <p><?= e(admin_trans('user_intro')) ?></p>
    </div>
    <div class="page-actions">
        <a href="<?= url('admin/user/add') ?>" class="btn-primary"><?= icon('plus', 16) ?><?= e(admin_trans('user_add')) ?></a>
    </div>
</div>

<?php if (empty($users)): ?>
    <div class="empty-state">
        <span class="empty-state-icon" aria-hidden="true"><?= icon('group', 24) ?></span>
        <p class="empty-state-title"><?= e(admin_trans('user_empty')) ?></p>
    </div>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th><?= e(admin_trans('user_username')) ?></th>
                <th><?= e(admin_trans('common_created')) ?></th>
                <th><?= e(admin_trans('user_last_login')) ?></th>
                <th class="col-actions col-actions-icons"><?= e(admin_trans('common_actions')) ?></th>
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
                <td class="actions col-actions-icons">
                    <a href="<?= url('admin/user/edit') . '?username=' . urlencode($name) ?>"
                       class="btn-small btn-icon"
                       title="<?= e(admin_trans('common_edit')) ?>"
                       aria-label="<?= e(admin_trans('common_edit')) ?>">
                        <?= icon('edit', 16) ?>
                    </a>

                    <?php if ($name !== $username): ?>
                        <form method="post"
                            action="<?= url('admin/user/remove') ?>"
                            data-confirm="<?= e(admin_trans('user_delete_confirm', ['name' => $name])) ?>"
                            data-confirm-title="<?= e(admin_trans('user_delete')) ?>"
                            class="inline-form js-confirm-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="username" value="<?= e($name) ?>">
                            <button type="submit" class="btn-delete btn-small btn-icon"
                                    title="<?= e(admin_trans('common_delete')) ?>"
                                    aria-label="<?= e(admin_trans('common_delete')) ?>">
                                <?= icon('trash', 16) ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <button
                            type="button"
                            class="btn-delete btn-small btn-icon"
                            title="<?= e(admin_trans('user_own_account_help')) ?>"
                            aria-label="<?= e(admin_trans('common_delete')) ?>"
                            disabled>
                            <?= icon('trash', 16) ?>
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
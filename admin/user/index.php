<?php

$pageTitle = admin_trans('nav_users');
$username  = current_username();

$roles = admin_roles();

// ----------------------------
// Filters
// ----------------------------
$role   = in_array((string) ($_GET['role'] ?? ''), $roles, true) ? (string) $_GET['role'] : '';
$search = trim((string) ($_GET['q'] ?? ''));

$users = load_users();

// Role counts are taken before the filters, so each tab shows its own total.
$roleCounts = array_fill_keys($roles, 0);

foreach ($users as $data) {
    $userRole = (string) ($data['role'] ?? '');

    if (isset($roleCounts[$userRole])) {
        $roleCounts[$userRole]++;
    }
}

$totalCount = count($users);

// The search covers everything that identifies an account, not just the
// username the table happens to show.
$visible = array_filter($users, static function (array $data) use ($role, $search): bool {
    if ($role !== '' && (string) ($data['role'] ?? '') !== $role) {
        return false;
    }

    if ($search === '') {
        return true;
    }

    $haystack = implode(' ', [
        (string) ($data['username'] ?? ''),
        (string) ($data['first_name'] ?? ''),
        (string) ($data['last_name'] ?? ''),
        (string) ($data['email'] ?? ''),
    ]);

    return stripos($haystack, $search) !== false;
});

// URL that preserves the current filters while changing one of them.
$filterUrl = function (array $overrides = []) use ($role, $search): string {
    $query = array_filter(array_merge([
        'role' => $role,
        'q'    => $search,
    ], $overrides), static fn($value) => $value !== '' && $value !== null);

    return url('admin/user') . ($query ? '?' . http_build_query($query) : '');
};

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

<div class="content-filters">
    <div class="status-tabs">
        <a href="<?= e($filterUrl(['role' => ''])) ?>"
           class="status-tab <?= $role === '' ? 'active' : '' ?>">
            <?= e(admin_trans('user_all_roles')) ?>
            <span class="status-tab-count"><?= (int) $totalCount ?></span>
        </a>
        <?php foreach ($roles as $roleCode): ?>
            <a href="<?= e($filterUrl(['role' => $roleCode])) ?>"
               class="status-tab <?= $role === $roleCode ? 'active' : '' ?>">
                <?= e(admin_role_label($roleCode)) ?>
                <span class="status-tab-count"><?= (int) $roleCounts[$roleCode] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="content-search">
        <?php if ($role !== ''): ?>
            <input type="hidden" name="role" value="<?= e($role) ?>">
        <?php endif; ?>
        <input type="search" name="q" value="<?= e($search) ?>"
               placeholder="<?= e(admin_trans('user_search')) ?>" aria-label="<?= e(admin_trans('user_search')) ?>">
        <?php if ($search !== ''): ?>
            <a href="<?= e($filterUrl(['q' => ''])) ?>" class="btn-small btn-muted"><?= e(admin_trans('common_clear')) ?></a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($visible)): ?>
    <div class="empty-state">
        <span class="empty-state-icon" aria-hidden="true"><?= icon('group', 24) ?></span>
        <p class="empty-state-title"><?= e(admin_trans('user_empty')) ?></p>
    </div>
<?php else: ?>
    <table class="content-table">
        <thead>
            <tr>
                <th><?= e(admin_trans('user_username')) ?></th>
                <th><?= e(admin_trans('user_role')) ?></th>
                <th><?= e(admin_trans('common_created')) ?></th>
                <th><?= e(admin_trans('user_last_login')) ?></th>
                <th class="col-actions col-actions-icons"><?= e(admin_trans('common_actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($visible as $name => $data): ?>
            <tr>
                <td><?= e($name) ?></td>
                <td><?= e(admin_role_label((string) ($data['role'] ?? ''))) ?></td>
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

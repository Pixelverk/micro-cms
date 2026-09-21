<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Roles & permissions
|--------------------------------------------------------------------------
| Three flat roles. Capabilities are plain strings checked through
| admin_can(); there is no inheritance engine, which keeps the whole model
| readable in one screen.
|
|   admin   everything
|   editor  content (including publishing), media, taxonomy, menus, forms
|   author  own content only, no publishing, no deletion
*/

/**
 * The roles a user can hold.
 *
 * @return list<string>
 */
function admin_roles(): array
{
    return ['admin', 'editor', 'author'];
}


function admin_role_label(string $role): string
{
    return match ($role) {
        'admin'  => 'Administrator',
        'editor' => 'Editor',
        'author' => 'Author',
        default  => ucfirst($role),
    };
}


/**
 * The role of the signed-in user, or a neutral default when anonymous.
 */
function admin_role(): string
{
    $user = current_user();

    return (string) ($user['role'] ?? 'author');
}


/**
 * The capability required to open each admin page (prefix => capability).
 * Pages not listed only require being signed in.
 */
function admin_page_capabilities(): array
{
    return [
        'settings'    => 'settings.manage',
        'user'        => 'users.manage',
        'profile'     => 'profile.own',
        'activity'    => 'activity.view',
        'utilities'   => 'settings.manage',
        'health'      => 'settings.manage',
        'menu'        => 'menu.manage',
        'redirects'   => 'redirects.manage',
        'category'    => 'taxonomy.manage',
        'tag'         => 'taxonomy.manage',
        'media'       => 'media.manage',
        'messages'    => 'forms.view',
    ];
}


/**
 * Capabilities granted to a role.
 *
 * @return array<string, bool>
 */
function admin_capabilities(?string $role = null): array
{
    if ($role === null || $role === '') {
        $role = admin_role();
    }

    // Unknown roles get the least privilege rather than everything.
    if (!in_array($role, admin_roles(), true)) {
        $role = 'author';
    }

    $all = [
        'content.view'     => false,
        'content.create'   => false,
        'content.edit.own' => false,
        'content.edit.any' => false,
        'content.publish'  => false,
        'content.delete'   => false,
        'content.bulk'     => false,
        'content.preview'  => true,
        'media.manage'     => false,
        'taxonomy.manage'  => false,
        'menu.manage'      => false,
        'redirects.manage' => false,
        'forms.view'       => false,
        'settings.manage'  => false,
        'users.manage'     => false,
        'profile.own'      => true,
        'activity.view'    => false,
        'docs.view'        => true,
    ];

    return match ($role) {
        'admin' => array_map(static fn() => true, $all),

        'editor' => array_merge($all, [
            'content.view'     => true,
            'content.create'   => true,
            'content.edit.own' => true,
            'content.edit.any' => true,
            'content.publish'  => true,
            'content.delete'   => true,
            'content.bulk'     => true,
            'media.manage'     => true,
            'taxonomy.manage'  => true,
            'menu.manage'      => true,
            'redirects.manage' => true,
            'forms.view'       => true,
        ]),

        // Author: own content only, drafts but never publishing. They may pick
        // from the media library but not manage it.
        default => array_merge($all, [
            'content.view'     => true,
            'content.create'   => true,
            'content.edit.own' => true,
        ]),
    };
}


/**
 * Does the given user (default: the current one) hold a capability?
 */
function admin_can(string $capability, ?array $user = null): bool
{
    if ($user === null) {
        $user = current_user();
    }

    // No user at all: nothing is granted, not even author-level defaults.
    if ($user === null) {
        return false;
    }

    return (bool) (admin_capabilities((string) ($user['role'] ?? ''))[$capability] ?? false);
}


/**
 * Stop the request when the current user lacks a capability.
 */
function require_capability(string $capability): void
{
    if (admin_can($capability)) {
        return;
    }

    log_activity('security.forbidden', null, null, $capability, [
        'path' => $_SERVER['REQUEST_URI'] ?? '',
    ]);

    http_response_code(403);
    render_admin_forbidden($capability);
    exit;
}


/**
 * May the current user edit this content item?
 *
 * Authors are limited to items they created; everyone else with
 * content.edit.any can edit anything.
 */
function can_edit_content(array $page): bool
{
    if (admin_can('content.edit.any')) {
        return true;
    }

    if (!admin_can('content.edit.own')) {
        return false;
    }

    $owner = $page['created_by'] ?? null;

    // Unattributed content (created before authorship tracking) is editable by
    // anyone who can edit their own, otherwise it would be stranded.
    if ($owner === null || (int) $owner === 0) {
        return true;
    }

    $userId = current_user_id();

    return $userId !== null && (int) $owner === $userId;
}


/**
 * Is this a request a signed-in user may make for their own account?
 *
 * The sidebar and top bar link every role to /admin/profile, which redirects
 * to /admin/user/edit. Managers reach it through users.manage; everyone else
 * gets this narrow exception, and admin/user/save.php still refuses any change
 * to another account, a username, or a role.
 */
function admin_is_self_service_request(string $page): bool
{
    $page = trim($page, '/');

    if ($page === 'user/edit') {
        $target = (string) ($_GET['username'] ?? '');
    } elseif ($page === 'user/save') {
        $target = (string) ($_POST['original_username'] ?? ($_POST['username'] ?? ''));
    } else {
        return false;
    }

    $current = current_user();
    $currentName = is_array($current) ? (string) ($current['username'] ?? '') : '';

    return $target !== '' && $currentName !== '' && $target === $currentName;
}


/**
 * The capability that guards an admin page, or null when it needs none.
 *
 * Longest matching prefix wins, so 'user/add' beats 'user'.
 */
function admin_page_capability(string $page): ?string
{
    $page = trim($page, '/');

    $required = null;
    $bestLength = 0;

    foreach (admin_page_capabilities() as $prefix => $capability) {
        if (($page === $prefix || str_starts_with($page, $prefix . '/')) && strlen($prefix) > $bestLength) {
            $required = $capability;
            $bestLength = strlen($prefix);
        }
    }

    return $required;
}


/**
 * May this user open an admin page? Pages with no capability are open to any
 * signed-in user. Navigation uses this so it never offers a page that 403s.
 */
function admin_can_open(string $page, ?array $user = null): bool
{
    $required = admin_page_capability($page);

    return $required === null || admin_can($required, $user);
}


/**
 * Abort an admin request when the current page needs a capability the user
 * does not have.
 */
function admin_guard(string $page): void
{
    $page = trim($page, '/');

    $required = admin_page_capability($page);

    if ($required === null || admin_can($required)) {
        return;
    }

    // Self-service profile edit/save for users without users.manage.
    if ($required === 'users.manage' && admin_is_self_service_request($page)) {
        return;
    }

    http_response_code(403);
    render_admin_forbidden($required);
    exit;
}


/**
 * Prevent locking everyone out: refuse to remove or demote the last admin.
 */
function admin_is_last_admin(int $userId): bool
{
    $pdo = db();

    $target = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
    $target->execute(['id' => $userId]);

    if ((string) $target->fetchColumn() !== 'admin') {
        return false;
    }

    $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

    return $admins <= 1;
}

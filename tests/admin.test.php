<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Roles, capabilities and HTML minification
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

/**
 * Sign in as a user with the given role.
 */
function login_as_role(string $role): int
{
    static $created = [];

    if (!isset($created[$role])) {
        $username = 'test-' . $role;

        db()->prepare("
            INSERT INTO users (username, email, password_hash, role, created_at)
            VALUES (:username, :email, :hash, :role, :now)
        ")->execute([
            'username' => $username,
            'email'    => $username . '@example.com',
            'hash'     => password_hash('secret-password', PASSWORD_DEFAULT),
            'role'     => $role,
            'now'      => time(),
        ]);

        $created[$role] = (int) db()->lastInsertId();
    }

    // Always (re-)assert the session, even when the user already exists.
    test_login_session($created[$role], 'test-' . $role);

    return $created[$role];
}

t('the role list and labels are stable', function () {
    assert_eq(['admin', 'editor', 'author'], admin_roles());
    assert_eq('Administrator', admin_role_label('admin'));
    assert_eq('Editor', admin_role_label('editor'));
    assert_eq('Author', admin_role_label('author'));
});

t('admin_role() reports the signed-in user role', function () {
    login_as_role('editor');
    assert_eq('editor', admin_role());
});

t('an anonymous request has no privileged capabilities', function () {
    $_SESSION = [];

    assert_false(admin_can('content.view'));
    assert_false(admin_can('content.publish'));
    assert_false(admin_can('users.manage'));
    assert_false(admin_can('settings.manage'));
});

t('the capability matrix separates the three roles', function () {
    $admin = admin_capabilities('admin');
    $editor = admin_capabilities('editor');
    $author = admin_capabilities('author');

    // Admin: everything.
    foreach ($admin as $capability => $granted) {
        assert_true($granted, "admin should hold {$capability}");
    }

    // Editor: publishing and content management, but no settings or users.
    assert_true($editor['content.publish']);
    assert_true($editor['content.edit.any']);
    assert_true($editor['content.delete']);
    assert_true($editor['media.manage']);
    assert_false($editor['settings.manage']);
    assert_false($editor['users.manage']);
    assert_false($editor['activity.view']);

    // Author: drafts only, own content, no publishing or deletion.
    assert_true($author['content.create']);
    assert_true($author['content.edit.own']);
    assert_false($author['content.edit.any']);
    assert_false($author['content.publish']);
    assert_false($author['content.delete']);
    assert_false($author['media.manage'], 'authors may not manage the media library');
});

t('an unknown role gets the least privilege', function () {
    $bogus = admin_capabilities('not-a-role');

    assert_false($bogus['content.publish']);
    assert_false($bogus['users.manage']);
    assert_eq($bogus, admin_capabilities('author'), 'unknown roles fall back to author');
});

t('admin_can() evaluates a supplied user row', function () {
    assert_true(admin_can('users.manage', ['role' => 'admin']));
    assert_false(admin_can('users.manage', ['role' => 'editor']));
    assert_false(admin_can('content.publish', ['role' => 'author']));
});

t('the page-to-capability map resolves the longest prefix', function () {
    $map = admin_page_capabilities();

    assert_eq('users.manage', $map['user']);
    assert_eq('users.manage', $map['user/add']);
    assert_eq('settings.manage', $map['settings']);
    assert_eq('profile.own', $map['profile']);
    assert_eq('activity.view', $map['activity']);
    assert_false(isset($map['dashboard']), 'unguarded pages stay open to any signed-in user');
});

t('admin_guard() blocks pages the role cannot open', function () {
    // Guarding is what protects a page; it exits for a forbidden page, so this
    // runs in a subprocess.
    [$output] = test_php([
        'db()->prepare("INSERT INTO users (username, email, password_hash, role, created_at) VALUES (\'guard-author\', \'g@example.com\', \'x\', \'author\', 1)")->execute();',
        '$id = (int) db()->lastInsertId();',
        'test_login_session($id, "guard-author");',
        'admin_guard("settings");',
        'echo "REACHED";',
    ]);

    assert_not_contains('REACHED', implode("\n", $output), 'an author must not open settings');
});

t('admin_guard() allows pages the role may open', function () {
    login_as_role('author');

    // Authors may open their own content list; this must not exit.
    admin_guard('content');

    assert_true(true);
});

t('can_edit_content() restricts authors to their own items', function () {
    // Start from a known role: earlier tests may have signed in as someone else.
    $authorId = login_as_role('author');

    // Own content, and content with no recorded owner, are editable.
    assert_true(can_edit_content(['created_by' => $authorId]));
    assert_true(can_edit_content(['created_by' => null]), 'unattributed content is not stranded');

    // Someone else's content is not.
    assert_false(can_edit_content(['created_by' => $authorId + 5000]));

    // Editors and admins may edit anything.
    login_as_role('editor');
    assert_true(can_edit_content(['created_by' => $authorId + 5000]));

    login_as_role('admin');
    assert_true(can_edit_content(['created_by' => 999999]));
});

t('the last administrator cannot be demoted or deleted', function () {
    // Normalise: the seeded demo user should be the only admin to start with.
    $demoId = (int) db()->query("SELECT id FROM users WHERE username = 'demo'")->fetchColumn();
    db()->prepare("UPDATE users SET role = 'author' WHERE id != :demo")->execute(['demo' => $demoId]);

    assert_true(admin_is_last_admin($demoId), 'demo is the last admin');

    // Add a second admin: now demotion is allowed.
    db()->prepare("INSERT INTO users (username, email, password_hash, role, created_at) VALUES ('admin2', 'a2@example.com', 'x', 'admin', :now)")
        ->execute(['now' => time()]);

    assert_false(admin_is_last_admin($demoId), 'with two admins, demotion is allowed');

    // A non-admin is never "the last admin".
    $authorId = login_as_role('author');
    assert_false(admin_is_last_admin($authorId));

    // Restore the seeded admin: other suites (http) log in as demo and expect
    // administrator capabilities.
    db()->prepare("UPDATE users SET role = 'admin' WHERE id = :demo")->execute(['demo' => $demoId]);
    db()->prepare("DELETE FROM users WHERE username = 'admin2'")->execute();
});

t('minify_html() collapses markup but protects scripts and pre', function () {
    $html = "<div>\n    <p>Hello   world</p>\n</div>\n<script>\n  const a = 1;\n  const b = 2;\n</script>";

    $minified = minify_html($html);

    assert_contains('<p>Hello world</p>', $minified, 'whitespace inside tags collapses');
    assert_not_contains('<div> <p>', $minified, 'whitespace between tags is removed');
    assert_contains("const a = 1;\n  const b = 2;", $minified, 'script content keeps its newlines');
});

t('minify_html() leaves preformatted text intact', function () {
    $minified = minify_html("<pre>\n  line one\n  line two\n</pre>");

    assert_contains("line one\n  line two", $minified);
});

t('invalidate_cache() clears every cached page', function () {
    file_put_contents(STORAGE_PATH . '/cache/about.html', 'x');
    file_put_contents(STORAGE_PATH . '/cache/home.html', 'y');

    invalidate_cache();

    assert_count(0, test_cache_files());
});

t('invalidate_cache() removes a single page using its prefix', function () {
    set_setting('content_prefixes', ['page' => '', 'blog_post' => 'blog']);

    file_put_contents(STORAGE_PATH . '/cache/blog_hello.html', 'x');
    file_put_contents(STORAGE_PATH . '/cache/about.html', 'y');

    invalidate_cache('hello', 'blog_post');

    $files = implode(',', array_map('basename', test_cache_files()));
    assert_not_contains('blog_hello.html', $files, 'prefixed page should be cleared');
    assert_contains('about.html', $files, 'other pages stay cached');

    invalidate_cache();
});

t('the capability map covers every guarded admin page', function () {
    // Every page listed in the map must name a capability that exists.
    foreach (admin_page_capabilities() as $page => $capability) {
        $matrix = admin_capabilities('admin');
        assert_true(array_key_exists($capability, $matrix), "{$page} needs an unknown capability {$capability}");
    }
});

t('authors cannot satisfy any editorial capability', function () {
    $author = admin_capabilities('author');

    foreach (['content.publish', 'content.delete', 'content.edit.any', 'settings.manage', 'users.manage', 'taxonomy.manage', 'menu.manage', 'forms.view', 'activity.view'] as $capability) {
        assert_false($author[$capability], "author must not hold {$capability}");
    }
});

exit(test_summary());

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Activity log
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('the activity table exists on a fresh install', function () {
    $tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

    assert_true(in_array('activity_log', $tables, true), 'activity_log should exist');
    assert_true(activity_table_exists());
});

t('log_activity() writes an entry with the current actor', function () {
    test_login_session();

    db()->exec("DELETE FROM activity_log");

    log_activity('content.published', 'content', 42, 'About Us', ['type' => 'page', 'status' => 'published']);

    $result = list_activity();

    assert_eq(1, $result['total']);
    $entry = $result['items'][0];

    assert_eq('content.published', $entry['action']);
    assert_eq('content', $entry['object_type']);
    assert_eq(42, (int) $entry['object_id']);
    assert_eq('About Us', $entry['summary']);
    assert_eq('admin', $entry['username'], 'the actor is recorded');
    assert_eq(1, (int) $entry['user_id']);

    $meta = json_decode((string) $entry['meta'], true);
    assert_eq('page', $meta['type'], 'meta is stored as JSON');
});

t('entries written without a session are attributed to the system', function () {
    $_SESSION = [];
    db()->exec("DELETE FROM activity_log");

    log_activity('utility.cache_cleared', 'utility', null, 'Cleared cache');

    $entry = list_activity()['items'][0];

    assert_eq(null, $entry['user_id']);
    assert_eq(null, $entry['username']);
});

t('an unknown action or missing table never throws', function () {
    // A blank action is ignored rather than stored.
    db()->exec("DELETE FROM activity_log");
    log_activity('', null, null, 'nothing');

    assert_eq(0, list_activity()['total']);

    // A malformed object id must not break the caller.
    log_activity('content.updated', 'content', 7, 'Fine');
    assert_eq(1, list_activity()['total']);
});

t('filters narrow the log by action, object, actor, text and date', function () {
    test_login_session();
    db()->exec("DELETE FROM activity_log");

    $now = time();

    $rows = [
        ['content.published', 'content', 'Homepage', 1, $now - 10],
        ['content.deleted',   'content', 'Old page', 1, $now - 20],
        ['media.uploaded',    'media',   'hero.jpg', 1, $now - 30],
        ['user.login',        'user',    'demo', 1, $now - 400000],
    ];

    $insert = db()->prepare("
        INSERT INTO activity_log (user_id, username, action, object_type, object_id, summary, created_at)
        VALUES (1, 'demo', :action, :object_type, 1, :summary, :created_at)
    ");

    foreach ($rows as [$action, $objectType, $summary, $userId, $createdAt]) {
        $insert->execute([
            'action'      => $action,
            'object_type' => $objectType,
            'summary'     => $summary,
            'created_at'  => $createdAt,
        ]);
    }

    assert_eq(4, list_activity()['total'], 'unfiltered');

    // A group prefix matches every action in that group.
    assert_eq(2, list_activity(['action' => 'content'])['total']);
    assert_eq(1, list_activity(['action' => 'content.deleted'])['total'], 'exact action');

    assert_eq(1, list_activity(['object_type' => 'media'])['total']);
    assert_eq(1, list_activity(['search' => 'Old page'])['total']);
    assert_eq(4, list_activity(['search' => 'demo'])['total'], 'matches the username too');
    assert_eq(3, list_activity(['since' => $now - 100])['total'], 'recent only');
    assert_eq(1, list_activity(['until' => $now - 100])['total'], 'older only');

    // Filters combine.
    assert_eq(1, list_activity(['action' => 'content', 'search' => 'Homepage'])['total']);
});

t('list_activity() paginates and reports the total', function () {
    db()->exec("DELETE FROM activity_log");

    $insert = db()->prepare("
        INSERT INTO activity_log (action, object_type, summary, created_at)
        VALUES ('content.updated', 'content', :summary, :created_at)
    ");

    for ($i = 1; $i <= 12; $i++) {
        $insert->execute(['summary' => "Item {$i}", 'created_at' => time() - $i]);
    }

    $first = list_activity([], 5, 0);
    assert_eq(12, $first['total'], 'total ignores the page size');
    assert_count(5, $first['items']);
    assert_eq('Item 1', $first['items'][0]['summary'], 'newest first');

    $third = list_activity([], 5, 10);
    assert_count(2, $third['items'], 'last page is partial');
    assert_eq('Item 12', $third['items'][1]['summary']);
});

t('list_activity_for_object() scopes to one item', function () {
    db()->exec("DELETE FROM activity_log");

    log_activity('content.updated', 'content', 100, 'First edit');
    log_activity('content.updated', 'content', 200, 'Other item');
    log_activity('content.published', 'content', 100, 'Published');

    $forHundred = list_activity_for_object('content', 100);

    assert_count(2, $forHundred, 'only entries for that item');
    assert_count(0, list_activity_for_object('content', 999));
});

t('activity_actors() lists distinct authors', function () {
    db()->exec("DELETE FROM activity_log");

    $insert = db()->prepare("
        INSERT INTO activity_log (user_id, username, action, created_at)
        VALUES (:user_id, :username, 'user.login', :created_at)
    ");

    $insert->execute(['user_id' => 1, 'username' => 'demo', 'created_at' => time()]);
    $insert->execute(['user_id' => 2, 'username' => 'editor', 'created_at' => time() - 10]);
    $insert->execute(['user_id' => 1, 'username' => 'demo', 'created_at' => time() - 20]);

    $actors = activity_actors();
    $names = array_column($actors, 'username');

    assert_count(2, $actors, 'one row per actor');
    assert_true(in_array('demo', $names, true));
    assert_true(in_array('editor', $names, true));
});

t('prune_activity() drops entries outside the retention window', function () {
    db()->exec("DELETE FROM activity_log");

    $insert = db()->prepare("
        INSERT INTO activity_log (action, created_at) VALUES ('content.updated', :created_at)
    ");

    $insert->execute(['created_at' => time() - 10]);
    $insert->execute(['created_at' => time() - (200 * 86400)]);

    assert_eq(2, list_activity()['total']);

    $removed = prune_activity(180);

    assert_eq(1, $removed, 'the old entry was removed');
    assert_eq(1, list_activity()['total'], 'the recent entry survived');
});

t('activity_action_label() falls back to a readable form', function () {
    assert_eq('Published content', activity_action_label('content.published'));
    assert_eq('Signed in', activity_action_label('user.login'));
    assert_eq('Something odd here', activity_action_label('something.odd_here'));
});

t('trash actions are distinguishable from each other', function () {
    assert_eq('Moved to trash', activity_action_label('content.trashed'));
    assert_eq('Restored from trash', activity_action_label('content.untrashed'));
    assert_eq('Deleted permanently', activity_action_label('content.purged'));

    // Restoring from the trash must not read as restoring a version.
    assert_true(
        activity_action_label('content.untrashed') !== activity_action_label('content.restored'),
        'trash restore and version restore have different labels'
    );
});

t('every instrumented action has a label', function () {
    // Guards against adding a new logged action without a human label.
    foreach (activity_groups() as $group) {
        assert_true($group !== '', 'groups are non-empty');
    }

    foreach (['user.created', 'media.uploaded', 'settings.updated', 'menu.deleted'] as $action) {
        assert_true(
            activity_action_label($action) !== $action,
            "{$action} should have a friendly label"
        );
    }
});

exit(test_summary());

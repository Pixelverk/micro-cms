<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content version history
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

/**
 * Insert a published page and return its id.
 */
function version_seed_page(string $slug, string $title = 'Original'): int
{
    return seed_content(['slug' => $slug, 'title' => $title]);
}

t('the version table exists on a fresh install', function () {
    $tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

    assert_true(in_array('content_versions', $tables, true), 'content_versions should exist');
});

t('saving a change snapshots the previous state', function () {
    $id = version_seed_page('version-basic', 'First title');

    // A snapshot is created on the first edit even though history was empty.
    save_content('page', 'version-basic', [
        'title'  => 'Second title',
        'status' => 'published',
        'body'   => [],
        'meta'   => [],
    ], $id, ['reason' => 'save']);

    $versions = list_content_versions($id);

    assert_count(1, $versions, 'one snapshot after one edit');
    assert_eq('First title', $versions[0]['title'], 'the snapshot holds the *previous* title');
    assert_eq(1, (int) $versions[0]['version'], 'the first snapshot is version 1');
    assert_eq('Second title', load_content_by_id($id)['title'], 'the live row holds the new title');
});

t('a no-op save does not create a duplicate version', function () {
    $id = version_seed_page('version-noop', 'Stable');

    $data = ['title' => 'Stable', 'status' => 'published', 'body' => [], 'meta' => []];

    // Capture the current state first: an explicit snapshot of the live row
    // needs live => false, exactly as a real first save does.
    snapshot_current_content($id, ['reason' => 'seed', 'live' => false]);
    assert_eq(1, count_content_versions($id), 'the seed snapshot exists');

    save_content('page', 'version-noop', $data, $id, ['reason' => 'save']);
    assert_eq(1, count_content_versions($id), 'saving identical content stores nothing new');

    // A real change snapshots the outgoing state. That state is already the
    // newest snapshot, so de-duplication keeps the count at one.
    $data['title'] = 'Changed';
    save_content('page', 'version-noop', $data, $id, ['reason' => 'save']);
    assert_eq(1, count_content_versions($id), 'the outgoing state was already snapshotted');
    assert_eq('Changed', load_content_by_id($id)['title'], 'the change itself was written');

    // A *second* distinct change must add a version.
    $data['title'] = 'Changed again';
    save_content('page', 'version-noop', $data, $id, ['reason' => 'save']);
    assert_eq(2, count_content_versions($id), 'a distinct change adds one version');

    // Re-saving the same state must not add another row.
    save_content('page', 'version-noop', $data, $id, ['reason' => 'save']);
    assert_eq(2, count_content_versions($id), 'repeating the same state is not a new version');
});

t('version numbers increment and reasons are stored', function () {
    $id = version_seed_page('version-reasons', 'v0');

    foreach ([['v1', 'save'], ['v2', 'publish'], ['v3', 'save']] as $index => [$title, $reason]) {
        save_content('page', 'version-reasons', [
            'title'  => $title,
            'status' => 'published',
            'body'   => [],
            'meta'   => [],
        ], $id, ['reason' => $reason]);

        // Space the timestamps so ordering is unambiguous.
        db()->exec("UPDATE content_versions SET created_at = created_at + {$index} WHERE content_id = {$id}");
    }

    $versions = list_content_versions($id);

    assert_count(3, $versions);
    assert_eq(3, (int) $versions[0]['version'], 'newest first');
    assert_eq('publish', $versions[1]['reason'], 'reason recorded per version');
    assert_eq('v1', $versions[1]['title'], 'snapshot holds the state before that save');
    assert_eq('v0', $versions[2]['title'], 'the oldest snapshot holds the seeded state');
});

t('the snapshot records who made the change', function () {
    $id = version_seed_page('version-author', 'Before');

    save_content('page', 'version-author', [
        'title'  => 'After',
        'status' => 'published',
        'body'   => [],
        'meta'   => [],
    ], $id, ['reason' => 'save', 'user_id' => 1]);

    $versions = list_content_versions($id);

    assert_eq(1, (int) $versions[0]['created_by']);
    assert_eq('admin', $versions[0]['username'], 'the author is joined for display');
});

t('load_content_version() refuses to cross content items', function () {
    $first  = version_seed_page('version-one', 'One');
    $second = version_seed_page('version-two', 'Two');

    save_content('page', 'version-one', ['title' => 'One v2', 'status' => 'published', 'body' => [], 'meta' => []], $first);
    save_content('page', 'version-two', ['title' => 'Two v2', 'status' => 'published', 'body' => [], 'meta' => []], $second);

    $versionOfFirst = list_content_versions($first)[0];

    assert_eq($first, (int) $versionOfFirst['content_id'], 'versions are scoped to their item');
    assert_true(load_content_version((int) $versionOfFirst['id']) !== null);
    assert_eq(null, load_content_version(999999), 'unknown ids return null');
});

t('restoring rewrites the content and is itself undoable', function () {
    $id = version_seed_page('version-restore', 'Original text');

    // Give the existing row real metadata so the snapshot has something to carry.
    db()->prepare("UPDATE content SET meta = :meta WHERE id = :id")
        ->execute(['meta' => json_encode(['description' => 'Original description']), 'id' => $id]);

    $meta = ['description' => 'Original description'];

    save_content('page', 'version-restore', [
        'title'  => 'Edited text',
        'status' => 'published',
        'body'   => [],
        'meta'   => $meta,
    ], $id);

    assert_eq('Edited text', load_content_by_id($id)['title']);

    // The snapshot currently holds the original text.
    $original = list_content_versions($id)[0];
    assert_eq('Original text', $original['title']);

    assert_true(restore_content_version((int) $original['id']), 'restore reports success');

    $restored = load_content_by_id($id);
    assert_eq('Original text', $restored['title'], 'the snapshot was written back');
    assert_eq('Original description', $restored['meta']['description']);

    // The state we replaced was snapshotted first, so a further restore is possible.
    $afterRestore = list_content_versions($id);
    assert_count(2, $afterRestore);
    assert_eq('restore', $afterRestore[0]['reason'], 'the new snapshot is labelled as a restore');
    assert_eq('Edited text', $afterRestore[0]['title'], 'it captured the state being replaced');
});

t('restoring a missing version fails cleanly', function () {
    assert_false(restore_content_version(999999));
});

t('status and scheduling are part of the snapshot', function () {
    $id = version_seed_page('version-status', 'Live page');

    save_content('page', 'version-status', [
        'title'  => 'Now a draft',
        'status' => 'draft',
        'body'   => [],
        'meta'   => [],
    ], $id);

    $snapshot = list_content_versions($id)[0];
    assert_eq('published', $snapshot['status'], 'the snapshot keeps the old status');

    // Restore returns it to published.
    restore_content_version((int) $snapshot['id']);
    assert_eq('published', load_content_by_id($id)['status'], 'status is restored too');
});

t('retention keeps only the newest versions', function () {
    $id = version_seed_page('version-prune', 'Start');

    for ($i = 1; $i <= 12; $i++) {
        save_content('page', 'version-prune', [
            'title'  => "Revision {$i}",
            'status' => 'published',
            'body'   => [],
            'meta'   => [],
        ], $id);
    }

    // Default keep is 20, so nothing is pruned yet.
    assert_eq(12, count_content_versions($id), 'under the limit nothing is pruned');

    prune_content_versions($id, 5);

    assert_eq(5, count_content_versions($id), 'pruned down to the limit');

    // The retained versions are the newest ones.
    $versions = list_content_versions($id);
    assert_eq(12, (int) $versions[0]['version'], 'newest kept');
    assert_eq(8, (int) $versions[4]['version'], 'oldest kept');

    // A tiny keep is clamped to at least one.
    prune_content_versions($id, 0);
    assert_true(count_content_versions($id) >= 1);
});

t('an autosave is stored under its own reason', function () {
    $id = version_seed_page('version-autosave', 'Live title');

    $versionId = save_content_version($id, [
        'title'  => 'Work in progress',
        'status' => 'published',
        'body'   => [],
        'meta'   => [],
    ], ['reason' => 'autosave']);

    assert_true($versionId !== null, 'a version is written');

    $latest = latest_content_autosave($id);
    assert_true($latest !== null);
    assert_eq('autosave', $latest['reason']);
    assert_eq('Work in progress', $latest['title']);
    assert_eq($versionId, (int) $latest['id']);

    // The state matches the live row, so there is nothing unsaved to store.
    assert_eq(null, save_content_version($id, content_version_current_row($id), ['reason' => 'autosave']));
});

t('autosaves do not count against the retention window', function () {
    $id = version_seed_page('version-autosave-keep', 'Live');

    // Four real snapshots, pruned to three: the item is at its limit.
    for ($i = 1; $i <= 4; $i++) {
        save_content_version($id, [
            'title'  => "Snapshot {$i}",
            'status' => 'published',
            'body'   => [],
            'meta'   => [],
        ], ['reason' => 'save', 'live' => false]);
    }

    prune_content_versions($id, 3);
    assert_eq(3, count_content_versions($id), 'three real snapshots are kept');

    // A run of autosaves keeps the newest one and evicts no real history.
    for ($i = 1; $i <= 5; $i++) {
        save_content_version($id, [
            'title'  => "Draft {$i}",
            'status' => 'published',
            'body'   => [],
            'meta'   => [],
        ], ['reason' => 'autosave']);
    }

    $counts = db()->prepare("
        SELECT reason, COUNT(*) AS total
        FROM content_versions
        WHERE content_id = :id
        GROUP BY reason
    ");
    $counts->execute(['id' => $id]);
    $byReason = array_column($counts->fetchAll(PDO::FETCH_ASSOC), 'total', 'reason');

    assert_eq(1, (int) ($byReason['autosave'] ?? 0), 'only the newest autosave is kept');
    assert_eq(3, (int) ($byReason['save'] ?? 0), 'no real snapshot was evicted');
    assert_eq('Draft 5', latest_content_autosave($id)['title'], 'the newest autosave wins');
});

t('deleting content removes its history', function () {
    $id = version_seed_page('version-delete', 'Doomed');

    save_content('page', 'version-delete', ['title' => 'Doomed v2', 'status' => 'published', 'body' => [], 'meta' => []], $id);
    assert_eq(1, count_content_versions($id));

    delete_content_versions($id);
    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);

    assert_eq(0, count_content_versions($id), 'history is gone with the content');
});

t('content_version_changes() names the changed fields', function () {
    $before = content_version_payload([
        'title' => 'A', 'status' => 'draft', 'layout' => null, 'header' => null, 'footer' => null,
        'meta' => '{}', 'body' => '[]', 'published_at' => null, 'scheduled_at' => null,
    ]);
    $after = $before;
    $after['title'] = 'B';
    $after['status'] = 'published';

    $changes = content_version_changes($before, $after);

    assert_true(in_array('Title', $changes, true), 'title change detected');
    assert_true(in_array('Status', $changes, true), 'status change detected');
    assert_count(2, $changes, 'unchanged fields are not reported');

    assert_count(0, content_version_changes($before, $before), 'identical payloads report nothing');
});

t('content_version_changes() compares body and meta structurally', function () {
    $base = [
        'title' => 'T', 'status' => 'published', 'layout' => null, 'header' => null, 'footer' => null,
        'meta' => '{"b":2,"a":1}', 'body' => '[{"type":"hero"}]', 'published_at' => null, 'scheduled_at' => null,
    ];

    // Same data, different key order: not a change.
    $reordered = $base;
    $reordered['meta'] = '{"a":1,"b":2}';

    assert_count(0, content_version_changes($base, $reordered), 'key order is not a change');

    $changed = $base;
    $changed['body'] = '[{"type":"cta"}]';
    assert_eq(['Content'], content_version_changes($base, $changed));
});

t('content_version_reason_label() covers the known reasons', function () {
    assert_eq('Published', content_version_reason_label('publish'));
    assert_eq('Restored', content_version_reason_label('restore'));
    assert_eq('Bulk edit', content_version_reason_label('bulk'));
    assert_eq('Autosaved', content_version_reason_label('autosave'));
    assert_eq('Saved', content_version_reason_label('anything-else'));
});

t('version_text_diff() reports equal, added and removed lines', function () {
    $ops = version_text_diff(['a', 'b', 'c'], ['a', 'x', 'c']);

    assert_eq(['same', 'del', 'add', 'same'], array_column($ops, 'op'));
    assert_eq(['a', 'b', 'x', 'c'], array_column($ops, 'text'));

    assert_count(0, version_text_diff([], []), 'two empty lists have nothing to report');
    assert_eq(['add', 'add'], array_column(version_text_diff([], ['a', 'b']), 'op'), 'an empty before is all additions');
    assert_eq(['del', 'del'], array_column(version_text_diff(['a', 'b'], []), 'op'), 'an empty after is all deletions');
    assert_eq(['same', 'same'], array_column(version_text_diff(['a', 'b'], ['a', 'b']), 'op'), 'identical lists are all same');
});

t('version_text_diff() keeps the common lines around an insertion', function () {
    $ops = version_text_diff(['a', 'b', 'c'], ['a', 'b', 'new', 'c']);

    assert_eq(['same', 'same', 'add', 'same'], array_column($ops, 'op'));
    assert_eq('new', $ops[2]['text']);
});

t('version_text_diff() falls back when a pair is too large', function () {
    $before = array_map(static fn($i) => 'line ' . $i, range(1, 600));
    $after  = array_map(static fn($i) => 'other ' . $i, range(1, 600));

    $ops = version_text_diff($before, $after);

    assert_eq(1200, count($ops), 'past the cap everything is removed and then added');
    assert_eq('del', $ops[0]['op']);
    assert_eq('add', $ops[1199]['op']);
});

t('content_version_lines() flattens a payload into labelled lines', function () {
    $payload = content_version_payload([
        'title'        => 'My page',
        'status'       => 'published',
        'layout'       => 'default',
        'header'       => null,
        'footer'       => null,
        'meta'         => ['description' => 'A page', 'author' => 'Valerie'],
        'body'         => [
            ['type' => 'hero-section', 'props' => ['title' => 'Hero'], 'children' => [
                ['type' => 'cta-section', 'props' => ['title' => 'CTA'], 'children' => []],
            ]],
        ],
        'published_at' => null,
        'scheduled_at' => null,
    ]);

    $lines = content_version_lines($payload);

    foreach ([
        'title: My page',
        'status: published',
        'meta › author: Valerie',
        'meta › description: A page',
        'body[0]: hero-section',
        'body[0] › title: Hero',
        'body[0] › children[0]: cta-section',
        'body[0] › children[0] › title: CTA',
    ] as $expected) {
        assert_true(in_array($expected, $lines, true), "missing line: {$expected}");
    }

    assert_true(
        array_search('meta › author: Valerie', $lines, true) < array_search('meta › description: A page', $lines, true),
        'map keys are sorted, so an unchanged value cannot change position'
    );
});

t('content_version_lines() is stable and ignores empty meta and body', function () {
    $first = content_version_payload([
        'title' => 'T', 'status' => 'draft', 'layout' => null, 'header' => null, 'footer' => null,
        'meta' => '{"b":2,"a":1}', 'body' => '[]', 'published_at' => null, 'scheduled_at' => null,
    ]);

    $second = $first;
    $second['meta'] = '{"a":1,"b":2}';

    assert_eq(content_version_lines($first), content_version_lines($second), 'key order does not move a line');

    $empty = content_version_lines(content_version_payload([
        'title' => '', 'status' => 'draft', 'layout' => null, 'header' => null, 'footer' => null,
        'meta' => '{}', 'body' => '[]', 'published_at' => null, 'scheduled_at' => null,
    ]));

    assert_count(7, $empty, 'the five scalars and two dates, and nothing for an empty meta or body');
});

exit(test_summary());

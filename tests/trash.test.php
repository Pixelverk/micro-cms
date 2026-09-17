<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trash
|--------------------------------------------------------------------------
|
| Deleting moves content to the trash: it leaves the front end and the admin
| list but keeps its version history until it is restored or purged. These
| checks also pin the admin loaders that make drafts editable again.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

function trash_reset(): void
{
    db()->exec("DELETE FROM content WHERE slug LIKE 'trash-%'");
    @unlink(STORAGE_PATH . '/.trash-purge');
}

t('the admin loaders see drafts and the front-end loaders do not', function () {
    trash_reset();

    $id = seed_content(['slug' => 'trash-draft', 'title' => 'Trash Draft', 'status' => 'draft', 'published_at' => null]);

    assert_eq(null, load_content_by_id($id), 'the front loader hides drafts');
    assert_true(load_content_by_id_admin($id) !== null, 'the admin loader sees drafts');

    assert_false(in_array($id, array_column(list_content('page'), 'id'), true), 'list_content hides drafts');
    assert_true(in_array($id, array_column(list_content_admin('page'), 'id'), true), 'list_content_admin sees drafts');

    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $id]);
});

t('trashing hides content everywhere and restoring brings it back', function () {
    trash_reset();

    $id = seed_content([
        'slug'         => 'trash-page',
        'title'        => 'Trash Page',
        'status'       => 'published',
        'published_at' => time() - 10,
    ]);

    assert_true(load_content_by_slug('trash-page') !== null, 'precondition: visible');

    assert_true(trash_content($id), 'the item is trashed');

    assert_eq(null, load_content_by_slug('trash-page'), 'hidden from the front');
    assert_eq(null, load_content_by_id($id), 'hidden from the front loader');
    assert_eq(null, load_content_by_id_admin($id), 'hidden from the admin loader too');

    assert_false(in_array($id, array_column(list_content_admin('page'), 'id'), true), 'absent from the live admin list');
    assert_true(in_array($id, array_column(list_content_admin('page', ['trashed' => true]), 'id'), true), 'present in the trash list');

    assert_true(restore_content($id), 'the item is restored');
    assert_true(load_content_by_slug('trash-page') !== null, 'visible again');
});

t('trashing and restoring cascade to child pages', function () {
    trash_reset();

    $parent = seed_content(['slug' => 'trash-parent', 'title' => 'Parent', 'status' => 'published', 'published_at' => time() - 10]);
    $child  = seed_content([
        'slug' => 'trash-child', 'title' => 'Child', 'parent_id' => $parent,
        'status' => 'published', 'published_at' => time() - 10,
    ]);

    assert_true(trash_content($parent));
    assert_eq(null, load_content_by_id_admin($child), 'the child is trashed with its parent');
    assert_eq(null, load_content_by_slug('trash-parent/trash-child'), 'the child URL is gone');

    assert_true(restore_content($parent));
    assert_true(load_content_by_id_admin($child) !== null, 'the child comes back');
});

t('purging removes the row and its version history', function () {
    trash_reset();

    $id = seed_content(['slug' => 'trash-purge', 'title' => 'Purge Me', 'status' => 'published', 'published_at' => time() - 10]);

    save_content('page', 'trash-purge', [
        'title'        => 'Purge Me v2',
        'status'       => 'published',
        'published_at' => time() - 5,
        'meta'         => [],
        'body'         => [],
    ], $id);

    assert_true(count_content_versions($id) > 0, 'precondition: history exists');

    assert_true(trash_content($id));
    assert_true(purge_content($id), 'the item is purged');

    $exists = db()->prepare("SELECT COUNT(*) FROM content WHERE id = :id");
    $exists->execute(['id' => $id]);

    assert_eq(0, (int) $exists->fetchColumn(), 'the row is gone');
    assert_eq(0, count_content_versions($id), 'the history is gone');
});

t('trashed content leaves search, recent lists and the sitemap', function () {
    trash_reset();

    $id = seed_content(['slug' => 'trash-seo', 'title' => 'Trash Seo Page', 'status' => 'published', 'published_at' => time() - 10]);
    search_index_content($id);

    assert_true(trash_content($id));

    assert_false(in_array($id, array_column(list_recent_content('page', 50), 'id'), true), 'absent from recent content');
    assert_not_contains('trash-seo', generate_sitemap(), 'absent from the sitemap');

    $found = search_content('Trash Seo Page');
    assert_false(in_array($id, array_column($found['items'], 'id'), true), 'absent from search');

    purge_content($id);
});

t('the retention purge removes only old trash', function () {
    trash_reset();

    $old = seed_content(['slug' => 'trash-old', 'title' => 'Old', 'status' => 'published', 'published_at' => time() - 10]);
    $new = seed_content(['slug' => 'trash-new', 'title' => 'New', 'status' => 'published', 'published_at' => time() - 10]);

    trash_content($old);
    trash_content($new);

    db()->prepare("UPDATE content SET deleted_at = :when WHERE id = :id")
        ->execute(['when' => time() - (40 * 86400), 'id' => $old]);

    assert_eq(1, content_purge_trashed(30), 'only the old item is purged');

    $exists = db()->prepare("SELECT COUNT(*) FROM content WHERE id = :id");
    $exists->execute(['id' => $old]);
    assert_eq(0, (int) $exists->fetchColumn(), 'the old item is gone');

    $exists->execute(['id' => $new]);
    assert_eq(1, (int) $exists->fetchColumn(), 'the recent item is kept');

    purge_content($new);
});

t('clearing the trash purges everything in it and nothing else', function () {
    trash_reset();

    $live = seed_content(['slug' => 'trash-live', 'title' => 'Live', 'status' => 'published', 'published_at' => time() - 10]);
    $a    = seed_content(['slug' => 'trash-a', 'title' => 'A', 'status' => 'published', 'published_at' => time() - 10]);
    $b    = seed_content(['slug' => 'trash-b', 'title' => 'B', 'status' => 'published', 'published_at' => time() - 10]);

    $before = content_trash_count();

    trash_content($a);
    trash_content($b);

    assert_eq($before + 2, content_trash_count(), 'the trash count follows the deletions');

    assert_eq($before + 2, content_empty_trash(), 'everything in the trash is purged, however recent');
    assert_eq(0, content_trash_count(), 'the trash is empty');

    assert_true(load_content_by_id_admin($live) !== null, 'live content is untouched');

    db()->prepare("DELETE FROM content WHERE id = :id")->execute(['id' => $live]);
});

t('a trashed slug is refused until it is purged', function () {
    trash_reset();

    $id = seed_content(['slug' => 'trash-slug', 'title' => 'Slug', 'status' => 'published', 'published_at' => time() - 10]);
    trash_content($id);

    $message = '';

    try {
        save_content('page', 'trash-slug', [
            'title'        => 'Reused',
            'status'       => 'published',
            'published_at' => time(),
            'meta'         => [],
            'body'         => [],
        ]);
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    assert_contains('trash', strtolower($message), 'a new item cannot claim a trashed slug');

    purge_content($id);
});

exit(test_summary());

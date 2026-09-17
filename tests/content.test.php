<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content status model, visibility and scheduled publishing
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

/**
 * Insert a content row directly, bypassing validation, for read tests.
 */
function seed_content(array $overrides = []): int
{
    $now = time();

    $row = array_merge([
        'type'         => 'page',
        'slug'         => 'sample-' . bin2hex(random_bytes(3)),
        'parent_id'    => null,
        'title'        => 'Sample',
        'status'       => 'published',
        'layout'       => null,
        'header'       => null,
        'footer'       => null,
        'meta'         => '{}',
        'body'         => '[]',
        'published_at' => $now,
        'scheduled_at' => null,
        'created_at'   => $now,
        'updated_at'   => $now,
    ], $overrides);

    $stmt = db()->prepare("
        INSERT INTO content (type, slug, parent_id, title, status, layout, header, footer, meta, body, published_at, scheduled_at, created_at, updated_at)
        VALUES (:type, :slug, :parent_id, :title, :status, :layout, :header, :footer, :meta, :body, :published_at, :scheduled_at, :created_at, :updated_at)
    ");
    $stmt->execute($row);

    return (int) db()->lastInsertId();
}

// ---------------------------------------------------------------------------
// Status resolution
// ---------------------------------------------------------------------------

t('resolve_content_status() covers every transition', function () {
    $now = 1_800_000_000;
    $future = $now + 3600;

    $draft = resolve_content_status(['status' => 'draft'], $now);
    assert_eq('draft', $draft['status']);
    assert_eq(null, $draft['published_at']);
    assert_eq(null, $draft['scheduled_at']);

    $live = resolve_content_status(['status' => 'published'], $now);
    assert_eq('published', $live['status']);
    assert_eq($now, $live['published_at'], 'publishing stamps "now"');

    $scheduled = resolve_content_status(['status' => 'scheduled', 'scheduled_at' => $future], $now);
    assert_eq('scheduled', $scheduled['status']);
    assert_eq($future, $scheduled['scheduled_at']);
    assert_eq(null, $scheduled['published_at'], 'scheduled is not yet published');

    // Publishing with a future date is really scheduling.
    $implied = resolve_content_status(['status' => 'published', 'scheduled_at' => $future], $now);
    assert_eq('scheduled', $implied['status']);

    $archived = resolve_content_status(['status' => 'archived'], $now);
    assert_eq('archived', $archived['status']);
    assert_eq(null, $archived['published_at']);

    // Unknown statuses fall back to draft rather than being stored.
    $bogus = resolve_content_status(['status' => 'exploded'], $now);
    assert_eq('draft', $bogus['status']);

    // A second save keeps the original go-live date.
    $resave = resolve_content_status(['status' => 'published', 'published_at' => $now - 500], $now);
    assert_eq($now - 500, $resave['published_at'], 'published_at is preserved');
});

t('content_statuses() lists the workflow in order', function () {
    assert_eq(['draft', 'scheduled', 'published', 'archived'], content_statuses());
});

// ---------------------------------------------------------------------------
// Visibility
// ---------------------------------------------------------------------------

t('anonymous visitors cannot see drafts, archived or future content', function () {
    $_SESSION = [];

    $visible = seed_content(['slug' => 'visible-page', 'status' => 'published', 'published_at' => time() - 10]);
    $draft   = seed_content(['slug' => 'draft-page', 'status' => 'draft', 'published_at' => null]);
    $archived = seed_content(['slug' => 'archived-page', 'status' => 'archived', 'published_at' => null]);
    $future  = seed_content(['slug' => 'future-page', 'status' => 'published', 'published_at' => time() + 3600]);

    assert_true(load_content_by_id($visible) !== null, 'published content is visible');
    assert_eq(null, load_content_by_id($draft), 'drafts are hidden');
    assert_eq(null, load_content_by_id($archived), 'archived content is hidden');
    assert_eq(null, load_content_by_id($future), 'future-dated content is hidden');

    assert_true(load_content_by_slug('visible-page') !== null);
    assert_eq(null, load_content_by_slug('draft-page'), 'draft slug must 404 for visitors');
    assert_eq(null, load_content_by_slug('future-page'), 'future slug must 404 for visitors');
});

t('signed-in editors see unpublished content only when previewing', function () {
    test_login_session();

    $draft = seed_content(['slug' => 'editor-draft', 'status' => 'draft', 'published_at' => null]);

    // Signed in but no preview token in the URL: behaves like a visitor, so an
    // editor browsing normally cannot fill the public cache with drafts.
    assert_eq(null, load_content_by_id($draft), 'no preview token means no drafts');
    assert_eq(null, load_content_by_slug('editor-draft'), 'no preview token means 404');

    // With the token, drafts become visible.
    test_preview_request();
    assert_true(load_content_by_id($draft) !== null, 'editors see drafts by id while previewing');
    assert_true(load_content_by_slug('editor-draft') !== null, 'editors see drafts by slug while previewing');

    test_request('GET', '/');
});

t('the visibility fragment is relaxed for preview requests only', function () {
    $_SESSION = [];
    assert_contains("status = 'published'", content_visibility_sql()['sql'], 'visitors get a filter');

    test_login_session();
    assert_contains("status = 'published'", content_visibility_sql()['sql'], 'a signed-in visit is still filtered');

    test_preview_request();
    assert_eq('', content_visibility_sql()['sql'], 'previewers get no filter');

    test_request('GET', '/');
});

t('list_content() filters by visibility and optional status', function () {
    $_SESSION = [];
    seed_content(['slug' => 'list-published-1', 'status' => 'published', 'published_at' => time() - 5]);
    seed_content(['slug' => 'list-draft-1', 'status' => 'draft', 'published_at' => null]);

    $publishedSlugs = array_column(list_content('page', ['status' => 'published']), 'slug');
    $draftSlugs     = array_column(list_content('page', ['status' => 'draft']), 'slug');

    assert_count(0, $draftSlugs, 'drafts are invisible to visitors');
    assert_true(in_array('list-published-1', $publishedSlugs, true), 'published row is listed');
    assert_false(in_array('list-draft-1', $publishedSlugs, true), 'draft row is not listed');

    // An editor in preview sees the drafts (other tests may have created some too).
    test_preview_request();
    $editorDraftSlugs = array_column(list_content('page', ['status' => 'draft']), 'slug');
    assert_true(in_array('list-draft-1', $editorDraftSlugs, true), 'previewers see drafts');
    test_request('GET', '/');
});

t('search filtering narrows list_content()', function () {
    $_SESSION = [];
    seed_content(['slug' => 'find-me-please', 'title' => 'Findable Page', 'status' => 'published', 'published_at' => time() - 5]);

    $hits = list_content('page', ['q' => 'Findable']);
    assert_count(1, $hits);
    assert_eq('Findable Page', $hits[0]['title']);

    assert_count(0, list_content('page', ['q' => 'NothingLikeThis']));
});

// ---------------------------------------------------------------------------
// Scheduled publishing
// ---------------------------------------------------------------------------

t('publish_due_content() publishes only what is due', function () {
    $due = seed_content([
        'slug'         => 'scheduled-due',
        'status'       => 'scheduled',
        'published_at' => null,
        'scheduled_at' => time() - 60,
    ]);

    $later = seed_content([
        'slug'         => 'scheduled-later',
        'status'       => 'scheduled',
        'published_at' => null,
        'scheduled_at' => time() + 3600,
    ]);

    $published = publish_due_content();

    assert_true(in_array($due, $published, true), 'the due item was published');
    assert_false(in_array($later, $published, true), 'the future item was left alone');

    $stmt = db()->prepare("SELECT status, published_at, scheduled_at FROM content WHERE id = :id");

    $stmt->execute(['id' => $due]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    assert_eq('published', $row['status']);
    assert_eq(null, $row['scheduled_at'], 'scheduled_at is cleared');
    assert_true((int) $row['published_at'] > 0, 'published_at is stamped');

    $stmt->execute(['id' => $later]);
    assert_eq('scheduled', $stmt->fetch(PDO::FETCH_ASSOC)['status']);
});

t('publish_due_content() is a no-op when nothing is due', function () {
    db()->exec("UPDATE content SET status = 'draft', scheduled_at = NULL");

    assert_count(0, publish_due_content());
});

t('publishing_check() respects its once-a-minute marker', function () {
    $marker = STORAGE_PATH . '/.publish-check';
    @unlink($marker);

    $due = seed_content([
        'slug'         => 'marker-due',
        'status'       => 'scheduled',
        'published_at' => null,
        'scheduled_at' => time() - 5,
    ]);

    publishing_check();

    $status = db()->prepare("SELECT status FROM content WHERE id = :id");
    $status->execute(['id' => $due]);
    assert_eq('published', $status->fetchColumn(), 'first call publishes');

    // A second due item must not be picked up within the same minute.
    $second = seed_content([
        'slug'         => 'marker-second',
        'status'       => 'scheduled',
        'published_at' => null,
        'scheduled_at' => time() - 5,
    ]);

    publishing_check();

    $status->execute(['id' => $second]);
    assert_eq('scheduled', $status->fetchColumn(), 'the marker suppresses a second run');

    // Force the marker stale and confirm it runs again.
    touch($marker, time() - 120);
    publishing_check();

    $status->execute(['id' => $second]);
    assert_eq('published', $status->fetchColumn(), 'a stale marker allows another run');

    @unlink($marker);
});

exit(test_summary());
